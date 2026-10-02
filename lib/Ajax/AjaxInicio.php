<?php
/**
 * zte_onu :: estado da tela inicial — quem sou, o que posso, e em que passo da configuracao
 * inicial esta esta instalacao.
 */
final class AjaxInicio
{
    /**
     * Modulos ja entregues. Um passo cujo modulo ainda nao existe aparece como "em breve" e sem
     * link, em vez de levar a uma tela que nao existe.
     */
    public const MODULOS_PRONTOS = ['olts.php', 'repositorios.php', 'firmwares.php', 'inventario.php', 'topologia.php',
                                    'regras.php', 'campanhas.php', 'fila.php'];

    public static function estado(array $e): array
    {
        $haAdmin = Permissao::haAdmin();
        $contagens = self::contagens();

        return [
            'usuario'     => Permissao::login(),
            'papeis'      => Permissao::papeis(),
            'pode_ver'    => Permissao::tem('ver'),
            'eh_admin'    => Permissao::tem('admin'),
            'ha_admin'    => $haAdmin,
            'modo_seguro' => Config::ligado('modo_seguro'),
            'versao'      => zte_versao(),
            'contagens'   => $contagens,
            'passos'      => self::passos($haAdmin, $contagens),
        ];
    }

    private static function contagens(): array
    {
        $n = fn(string $sql) => (int) Db::valor($sql);
        return [
            'olts'          => $n('SELECT COUNT(*) FROM tab_zte_olt WHERE ativo = 1'),
            'repositorios'  => $n('SELECT COUNT(*) FROM tab_zte_repositorio WHERE ativo = 1'),
            'vinculos_ftp'  => $n('SELECT COUNT(*) FROM tab_zte_olt_repositorio'),
            'firmwares'     => $n("SELECT COUNT(*) FROM tab_zte_firmware WHERE estado = 'disponivel'"),
            'compat'        => $n('SELECT COUNT(*) FROM tab_zte_firmware_compat'),
            'onus'          => $n('SELECT COUNT(*) FROM tab_zte_onu'),
            'onus_online'   => $n("SELECT COUNT(*) FROM tab_zte_onu WHERE estado = 'online'"),
            'campanhas'     => $n("SELECT COUNT(*) FROM tab_zte_campanha WHERE estado IN ('aprovada','executando','pausada')"),
            'simuladas'     => $n("SELECT COUNT(*) FROM tab_zte_campanha WHERE simulada_em IS NOT NULL"),
            'falhas_24h'    => $n("SELECT COUNT(*) FROM tab_zte_job WHERE estado IN ('falha','inconclusivo') AND criado_em >= NOW() - INTERVAL 1 DAY"),
            'olts_testadas' => $n("SELECT COUNT(*) FROM tab_zte_olt WHERE ativo = 1 AND ultimo_teste_resultado = 'ok'"),
            'repos_testados' => $n("SELECT COUNT(*) FROM tab_zte_repositorio WHERE ativo = 1 AND ultimo_teste_resultado = 'ok'"),
            'desatualizadas' => InventarioServico::contarDesatualizadas(),
        ];
    }

    /** Os passos do assistente de configuracao inicial (plano §8). */
    private static function passos(bool $haAdmin, array $c): array
    {
        $def = [
            ['admin',        'Administrador do addon',      'Defina quem administra este addon.',                          $haAdmin,                   null],
            ['olt',          'Cadastrar a OLT',             'Nome, endereço, porta e protocolo de acesso.',                $c['olts'] > 0,             'olts.php'],
            ['olt_teste',    'Testar o acesso à OLT',       'Login, versão e placas detectadas; driver compatível.',        $c['olts_testadas'] > 0,    'olts.php'],
            ['repositorio',  'Configurar o servidor FTP',   'Repositório de firmwares e teste de conexão.',                 $c['repos_testados'] > 0,   'repositorios.php'],
            ['olt_ftp',      'Acesso da OLT ao FTP',        'Endereço e credencial que a OLT usa para buscar o firmware.',  $c['vinculos_ftp'] > 0,     'repositorios.php'],
            ['firmware',     'Enviar um firmware',          'Upload para o FTP, SHA-256 e verificação remota.',             $c['firmwares'] > 0,        'firmwares.php'],
            ['compat',       'Validar a compatibilidade',   'Modelos e revisões de hardware aceitos pelo firmware.',        $c['compat'] > 0,           'firmwares.php'],
            ['simulacao',    'Simular uma campanha',        'Ver quem seria atualizado, sem executar nada.',                $c['simuladas'] > 0,        'campanhas.php'],
        ];
        $saida = [];
        foreach ($def as [$id, $titulo, $desc, $feito, $link]) {
            $pronto = $link === null || in_array($link, self::MODULOS_PRONTOS, true);
            $saida[] = [
                'id'        => $id,
                'titulo'    => $titulo,
                'descricao' => $desc,
                'estado'    => $feito ? 'feito' : ($pronto ? 'pendente' : 'em_breve'),
                'link'      => $pronto ? $link : null,
            ];
        }
        return $saida;
    }
}
