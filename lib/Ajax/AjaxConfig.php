<?php
/**
 * zte_onu :: configuracoes gerais.
 */
final class AjaxConfig
{
    /** Texto que o administrador digita para desligar o modo seguro. */
    public const CONFIRMA_MODO_SEGURO = 'DESLIGAR';

    public static function listar(array $e): array
    {
        return [
            'itens'       => Config::paraTela(),
            'pode_editar' => Permissao::tem('admin'),
            'confirma_modo_seguro' => self::CONFIRMA_MODO_SEGURO,
        ];
    }

    /**
     * Grava varias chaves de uma vez, tudo ou nada. Desligar o modo seguro exige a confirmacao
     * digitada: e a chave que libera atualizacao em massa.
     */
    public static function salvar(array $e): array
    {
        $valores = isset($e['valores']) && is_array($e['valores']) ? $e['valores'] : [];
        $usuario = Permissao::login();

        if (array_key_exists('modo_seguro', $valores)
            && !Validar::bool($valores['modo_seguro']) && Config::ligado('modo_seguro')
            && (string) ($e['confirmacao'] ?? '') !== self::CONFIRMA_MODO_SEGURO) {
            throw new ZteErro('ZTE-SYS-002', ['chave' => 'modo_seguro'],
                'Para desligar o modo seguro, digite ' . self::CONFIRMA_MODO_SEGURO . ' na confirmação.');
        }

        $antes = [];
        $depois = [];
        try {
            Db::transacao(function () use ($valores, $usuario, &$antes, &$depois) {
                foreach ($valores as $chave => $valor) {
                    $chave = (string) $chave;
                    try {
                        [$a, $d] = Config::set($chave, $valor, $usuario);
                    } catch (ZteErro $ex) {
                        throw new ZteErro($ex->codigo(), $ex->detalhes() + ['chave' => $chave],
                            (Config::DEFINICOES[$chave]['rotulo'] ?? $chave) . ': ' . $ex->getMessage());
                    }
                    if ($a !== $d) {
                        $antes[$chave] = $a;
                        $depois[$chave] = $d;
                    }
                }
            });
        } catch (Throwable $ex) {
            Config::limparCache();
            throw $ex;
        }

        if ($depois) {
            Auditoria::registrar('config_alterar', 'config', null, $antes, $depois);
            Log::info('config.alterar', ['chaves' => array_keys($depois)]);
        }
        return ['alteradas' => array_keys($depois), 'itens' => Config::paraTela()];
    }
}
