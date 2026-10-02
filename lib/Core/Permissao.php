<?php
/**
 * zte_onu :: controle de acesso por operacao.
 *
 * Cada login do MK-AUTH recebe papeis neste addon (tab_zte_permissao). A configuracao e
 * separada da execucao: quem cadastra OLT nao aprova campanha so por isso.
 *
 * O primeiro administrador: enquanto nao existe NENHUM admin, qualquer usuario logado no
 * painel pode assumir a administracao — uma unica vez, auditado. E o mesmo modelo de "quem
 * instala configura" do MK-AUTH; depois disso so um admin concede papeis.
 *
 * A verificacao acontece no servidor, no roteador AJAX, para toda operacao. O front so esconde
 * botoes por conveniencia.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/ZteErro.php';
require_once __DIR__ . '/Validar.php';

final class Permissao
{
    public const PAPEIS = [
        'admin'                  => 'Administrador: tudo, inclusive conceder permissões',
        'ver'                    => 'Consultar telas, inventário e relatórios',
        'olt.configurar'         => 'Cadastrar e testar OLTs',
        'repositorio.configurar' => 'Cadastrar e testar repositórios FTP',
        'repositorio.excluir'    => 'Excluir arquivos no FTP',
        'firmware.gerenciar'     => 'Enviar, cadastrar e verificar firmwares',
        'regra.gerenciar'        => 'Criar e alterar regras de atualização',
        'campanha.criar'         => 'Criar e simular campanhas',
        'campanha.aprovar'       => 'Aprovar campanhas',
        'campanha.operar'        => 'Pausar, retomar e abortar campanhas; atualizar 1 ONU em modo seguro',
        'job.reprocessar'        => 'Reprocessar jobs com falha ou inconclusivos',
        'onu.atualizar_avulso'   => 'Atualizar uma ONU na hora pelo inventário (técnico no local, cliente avisado)',
    ];

    private static string $login = '';
    /** @var array<string,array<string,true>> */
    private static array $cache = [];

    public static function configurar(string $login): void
    {
        self::$login = $login;
    }

    public static function login(): string
    {
        return self::$login;
    }

    /** @return string[] papeis do login */
    public static function papeis(?string $login = null): array
    {
        $login = $login ?? self::$login;
        if (!isset(self::$cache[$login])) {
            self::$cache[$login] = [];
            foreach (Db::todos('SELECT papel FROM tab_zte_permissao WHERE login = ?', [$login]) as $r) {
                self::$cache[$login][$r['papel']] = true;
            }
        }
        return array_keys(self::$cache[$login]);
    }

    /** admin pode tudo; 'ver' vale para quem tem qualquer papel. */
    public static function tem(string $papel, ?string $login = null): bool
    {
        $meus = array_flip(self::papeis($login));
        if (isset($meus['admin'])) {
            return true;
        }
        if ($papel === 'ver') {
            return $meus !== [];
        }
        return isset($meus[$papel]);
    }

    public static function haAdmin(): bool
    {
        return (int) Db::valor("SELECT COUNT(*) FROM tab_zte_permissao WHERE papel = 'admin'") > 0;
    }

    /**
     * Torna $login o primeiro administrador. Recusa se ja existe algum.
     * O SELECT ... FOR UPDATE trava o intervalo do indice de papel: dois usuarios clicando ao
     * mesmo tempo nao viram dois "primeiros" administradores.
     */
    public static function assumirAdmin(string $login): void
    {
        $login = Validar::login($login);
        try {
            Db::transacao(function () use ($login) {
                $n = (int) Db::valor("SELECT COUNT(*) FROM tab_zte_permissao WHERE papel = 'admin' FOR UPDATE");
                if ($n > 0) {
                    throw new ZteErro('ZTE-AUTH-004', [], null, 409);
                }
                Db::exec("INSERT INTO tab_zte_permissao (login, papel, criado_por, criado_em) VALUES (?, 'admin', ?, NOW())",
                    [$login, $login]);
            });
        } catch (PDOException $e) {
            // Dois cliques simultaneos: os dois pegam o lock de intervalo e o InnoDB derruba um
            // por deadlock (1213). Quem perdeu ve a mesma resposta de "ja definido".
            if ((int) ($e->errorInfo[1] ?? 0) === 1213) {
                throw new ZteErro('ZTE-AUTH-004', [], null, 409);
            }
            throw $e;
        }
        self::esquecer();
    }

    /**
     * Substitui o conjunto de papeis de um login. Devolve [antes, depois] para a auditoria.
     * Recusa deixar o addon sem nenhum administrador.
     *
     * @param string[] $papeis
     * @return array{0:string[],1:string[]}
     */
    public static function definir(string $login, array $papeis, string $por): array
    {
        $login = Validar::login($login);
        $papeis = array_values(array_unique(array_filter($papeis, fn($p) => isset(self::PAPEIS[$p]))));
        sort($papeis);

        return Db::transacao(function () use ($login, $papeis, $por) {
            $antes = array_column(Db::todos('SELECT papel FROM tab_zte_permissao WHERE login = ? ORDER BY papel FOR UPDATE', [$login]), 'papel');
            if (in_array('admin', $antes, true) && !in_array('admin', $papeis, true)) {
                $outros = (int) Db::valor("SELECT COUNT(*) FROM tab_zte_permissao WHERE papel = 'admin' AND login <> ? FOR UPDATE", [$login]);
                if ($outros === 0) {
                    throw new ZteErro('ZTE-AUTH-005', [], null, 409);
                }
            }
            Db::exec('DELETE FROM tab_zte_permissao WHERE login = ?', [$login]);
            foreach ($papeis as $p) {
                Db::exec('INSERT INTO tab_zte_permissao (login, papel, criado_por, criado_em) VALUES (?, ?, ?, NOW())',
                    [$login, $p, $por]);
            }
            self::esquecer();
            return [$antes, $papeis];
        });
    }

    /** @return array<int,array{login:string,papeis:string[]}> */
    public static function listar(): array
    {
        $por = [];
        foreach (Db::todos('SELECT login, papel FROM tab_zte_permissao ORDER BY login, papel') as $r) {
            $por[$r['login']][] = $r['papel'];
        }
        $saida = [];
        foreach ($por as $login => $papeis) {
            $saida[] = ['login' => $login, 'papeis' => $papeis];
        }
        return $saida;
    }

    /**
     * Usuarios do MK-AUTH (sis_acesso, somente leitura) para o seletor da tela.
     * Num banco sem a tabela (testes), devolve lista vazia e a validacao de login e pulada.
     *
     * @return array<int,array{login:string,nome:string,ativo:bool}>
     */
    public static function usuariosMkauth(): array
    {
        if (!Db::tabelaExiste('sis_acesso')) {
            return [];
        }
        $saida = [];
        foreach (Db::todos("SELECT login, nome, ativo FROM sis_acesso WHERE login IS NOT NULL AND login <> '' ORDER BY login") as $r) {
            $saida[] = ['login' => (string) $r['login'], 'nome' => (string) ($r['nome'] ?? ''), 'ativo' => ($r['ativo'] ?? 'sim') === 'sim'];
        }
        return $saida;
    }

    /** O login existe no MK-AUTH? (sem sis_acesso, aceita — e o caso dos testes) */
    public static function loginExisteNoMkauth(string $login): bool
    {
        if (!Db::tabelaExiste('sis_acesso')) {
            return true;
        }
        return (int) Db::valor('SELECT COUNT(*) FROM sis_acesso WHERE login = ?', [$login]) > 0;
    }

    public static function esquecer(): void
    {
        self::$cache = [];
    }
}
