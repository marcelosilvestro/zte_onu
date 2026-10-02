<?php
/**
 * zte_onu :: configuracao geral, chave/valor com tipo e faixa.
 *
 * Os padroes vivem AQUI, nao no banco: uma instalacao nova funciona sem seed, e uma
 * atualizacao que muda um padrao vale para quem nunca alterou aquele valor.
 * tab_zte_config guarda so o que o administrador mudou e os valores internos.
 */
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Validar.php';
require_once __DIR__ . '/ZteErro.php';

final class Config
{
    /**
     * tipo: bool | int | hora | enum.  grupo: agrupamento na tela.
     * Chaves com 'interno' => true nao aparecem nem sao alteraveis pela interface.
     */
    public const DEFINICOES = [
        'modo_seguro' => ['tipo' => 'bool', 'padrao' => '1', 'grupo' => 'Segurança',
            'rotulo' => 'Modo seguro', 'ajuda' => 'Ligado: só permite atualização de UMA ONU por vez, para teste. Campanhas ficam bloqueadas.'],
        'separar_criar_aprovar' => ['tipo' => 'bool', 'padrao' => '1', 'grupo' => 'Segurança',
            'rotulo' => 'Quem cria não aprova', 'ajuda' => 'Ligado: a campanha precisa ser aprovada por outro usuário (o administrador pode aprovar a própria).'],
        'bloqueio_auth_falhas' => ['tipo' => 'int', 'padrao' => '3', 'min' => 1, 'max' => 20, 'grupo' => 'Segurança',
            'rotulo' => 'Falhas de login até bloquear', 'ajuda' => 'Falhas de autenticação seguidas na OLT ou no FTP antes de pausar novas tentativas.'],
        'bloqueio_auth_min' => ['tipo' => 'int', 'padrao' => '15', 'min' => 1, 'max' => 1440, 'grupo' => 'Segurança',
            'rotulo' => 'Pausa após bloqueio (min)', 'ajuda' => 'Evita travar a conta no equipamento por excesso de tentativas.'],

        'janela_inicio' => ['tipo' => 'hora', 'padrao' => '02:00', 'grupo' => 'Execução',
            'rotulo' => 'Janela padrão: início', 'ajuda' => 'Horário a partir do qual campanhas podem executar (padrão de novas campanhas).'],
        'janela_fim' => ['tipo' => 'hora', 'padrao' => '05:00', 'grupo' => 'Execução',
            'rotulo' => 'Janela padrão: fim', 'ajuda' => 'Horário a partir do qual nenhum job novo é iniciado.'],
        'max_por_pon' => ['tipo' => 'int', 'padrao' => '2', 'min' => 1, 'max' => 64, 'grupo' => 'Execução',
            'rotulo' => 'Máximo simultâneo por PON', 'ajuda' => 'ONUs atualizando ao mesmo tempo na mesma PON.'],
        'max_concorrentes' => ['tipo' => 'int', 'padrao' => '4', 'min' => 1, 'max' => 64, 'grupo' => 'Execução',
            'rotulo' => 'Máximo simultâneo na OLT', 'ajuda' => 'ONUs atualizando ao mesmo tempo na OLT inteira.'],
        'max_falhas' => ['tipo' => 'int', 'padrao' => '3', 'min' => 1, 'max' => 1000, 'grupo' => 'Execução',
            'rotulo' => 'Falhas até pausar', 'ajuda' => 'Número de jobs com falha que pausa a campanha automaticamente.'],
        'max_falhas_pct' => ['tipo' => 'int', 'padrao' => '10', 'min' => 1, 'max' => 100, 'grupo' => 'Execução',
            'rotulo' => 'Falhas até pausar (%)', 'ajuda' => 'Percentual de falhas sobre os jobs já executados que pausa a campanha.'],
        'max_transferencias_olt' => ['tipo' => 'int', 'padrao' => '1', 'min' => 1, 'max' => 16, 'grupo' => 'Execução',
            'rotulo' => 'Transferências simultâneas por OLT', 'ajuda' => 'A OLT baixa cada firmware para a própria flash antes de enviar à ONU. Mantenha 1 até confirmar, no piloto, quanto espaço duas transferências ao mesmo tempo ocupam.'],
        'retentativas' => ['tipo' => 'int', 'padrao' => '1', 'min' => 0, 'max' => 5, 'grupo' => 'Execução',
            'rotulo' => 'Retentativas por ONU', 'ajuda' => 'Novas tentativas automáticas, sempre após reconciliar o estado real da ONU.'],
        'simulacao_validade_h' => ['tipo' => 'int', 'padrao' => '12', 'min' => 1, 'max' => 168, 'grupo' => 'Execução',
            'rotulo' => 'Validade da simulação (h)', 'ajuda' => 'Depois disso, a campanha precisa ser simulada de novo antes de aprovar.'],
        'inventario_maximo_h' => ['tipo' => 'int', 'padrao' => '6', 'min' => 1, 'max' => 168, 'grupo' => 'Execução',
            'rotulo' => 'Inventário recente (h)', 'ajuda' => 'A simulação avisa quando o inventário da OLT é mais velho que isso.'],
        'job_timeout_min' => ['tipo' => 'int', 'padrao' => '15', 'min' => 2, 'max' => 240, 'grupo' => 'Execução',
            'rotulo' => 'Job sem sinal após (min)', 'ajuda' => 'Tempo sem batimento até o job ser considerado interrompido e ir para reconciliação.'],

        'integridade_politica' => ['tipo' => 'enum', 'padrao' => 'completa', 'opcoes' => ['completa', 'tamanho'], 'grupo' => 'Firmware',
            'rotulo' => 'Verificação do arquivo remoto', 'ajuda' => 'completa: baixa o arquivo do FTP e confere o SHA-256. tamanho: confere só o tamanho (mais rápido, menos seguro).'],
        'firmware_max_mb' => ['tipo' => 'int', 'padrao' => '64', 'min' => 1, 'max' => 512, 'grupo' => 'Firmware',
            'rotulo' => 'Tamanho máximo do firmware (MB)', 'ajuda' => 'Limite de upload pela interface.'],

        'inventario_intervalo_min' => ['tipo' => 'int', 'padrao' => '60', 'min' => 5, 'max' => 1440, 'grupo' => 'Sincronização',
            'rotulo' => 'Inventário a cada (min)', 'ajuda' => 'Intervalo da leitura automática das ONUs (somente leitura na OLT).'],
        'sync_repo_intervalo_min' => ['tipo' => 'int', 'padrao' => '360', 'min' => 0, 'max' => 10080, 'grupo' => 'Sincronização',
            'rotulo' => 'Sincronizar FTP a cada (min)', 'ajuda' => '0 desliga. A sincronização automática só marca estados, nunca apaga.'],

        // Internos
        'cofre_digital'     => ['tipo' => 'texto', 'padrao' => '', 'interno' => true],
        'admin_definido_em' => ['tipo' => 'texto', 'padrao' => '', 'interno' => true],
    ];

    private static array $cache = [];
    private static bool $carregado = false;

    public static function get(string $chave): string
    {
        if (!isset(self::DEFINICOES[$chave])) {
            throw new ZteErro('ZTE-CFG-001', ['chave' => $chave]);
        }
        self::carregar();
        $v = self::$cache[$chave] ?? null;
        return ($v === null || $v === '') ? (string) self::DEFINICOES[$chave]['padrao'] : (string) $v;
    }

    public static function int(string $chave): int
    {
        return (int) self::get($chave);
    }

    public static function ligado(string $chave): bool
    {
        return self::get($chave) === '1';
    }

    /**
     * Grava um valor validado. Devolve [antes, depois] para a auditoria de quem chamou.
     * @return array{0:string,1:string}
     */
    public static function set(string $chave, $valor, string $usuario, bool $permitirInterno = false): array
    {
        $def = self::DEFINICOES[$chave] ?? null;
        if ($def === null) {
            throw new ZteErro('ZTE-CFG-001', ['chave' => $chave]);
        }
        if (!empty($def['interno']) && !$permitirInterno) {
            throw new ZteErro('ZTE-CFG-002', ['chave' => $chave]);
        }
        $novo = self::normalizar($def, $valor);
        $antes = self::get($chave);

        Db::exec(
            'INSERT INTO tab_zte_config (chave, valor, alterado_por, alterado_em)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), alterado_por = VALUES(alterado_por), alterado_em = NOW()',
            [$chave, $novo, $usuario]
        );
        self::$cache[$chave] = $novo;
        return [$antes, $novo];
    }

    /** Tudo o que a tela de configuracoes mostra, agrupado, com o valor efetivo. */
    public static function paraTela(): array
    {
        $saida = [];
        foreach (self::DEFINICOES as $chave => $def) {
            if (!empty($def['interno'])) {
                continue;
            }
            $saida[] = [
                'chave'  => $chave,
                'grupo'  => $def['grupo'],
                'tipo'   => $def['tipo'],
                'rotulo' => $def['rotulo'],
                'ajuda'  => $def['ajuda'],
                'min'    => $def['min'] ?? null,
                'max'    => $def['max'] ?? null,
                'opcoes' => $def['opcoes'] ?? null,
                'padrao' => $def['padrao'],
                'valor'  => self::get($chave),
            ];
        }
        return $saida;
    }

    public static function limparCache(): void
    {
        self::$cache = [];
        self::$carregado = false;
    }

    private static function carregar(): void
    {
        if (self::$carregado) {
            return;
        }
        foreach (Db::todos('SELECT chave, valor FROM tab_zte_config') as $r) {
            self::$cache[$r['chave']] = $r['valor'];
        }
        self::$carregado = true;
    }

    private static function normalizar(array $def, $valor): string
    {
        switch ($def['tipo']) {
            case 'bool':
                return Validar::bool($valor) ? '1' : '0';
            case 'int':
                return (string) Validar::inteiro($valor, (int) $def['min'], (int) $def['max']);
            case 'hora':
                return Validar::hora($valor);
            case 'enum':
                if (!in_array((string) $valor, $def['opcoes'], true)) {
                    throw new ZteErro('ZTE-SYS-002', ['opcoes' => $def['opcoes']]);
                }
                return (string) $valor;
            default:
                return Validar::texto($valor, 255);
        }
    }
}
