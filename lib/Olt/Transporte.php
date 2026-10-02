<?php
/**
 * zte_onu :: contrato do canal de comunicacao com a OLT.
 *
 * O driver so conversa com a OLT por aqui. Ha duas implementacoes:
 *   TransporteTelnet   a OLT de verdade
 *   TransporteFixture  OLT simulada, que responde com saidas reais gravadas (testes e demonstracao)
 *
 * O transporte nao conhece comando nenhum: quem monta a linha e o Comandos do driver, a partir
 * de uma lista fechada. Linha com quebra de linha e recusada aqui, como ultima barreira.
 */
require_once __DIR__ . '/../Core/ZteErro.php';

interface Transporte
{
    /** Abre a conexao e faz login (e enable, se preciso). Lanca OltFalha. */
    public function conectar(): void;

    /** Executa UMA linha e devolve a saida limpa (sem eco, sem prompt, sem paginacao). */
    public function executar(string $linha): string;

    /** Nome que a OLT mostra no prompt (ex.: OLT-c320_1). Vazio antes de conectar. */
    public function nomeEquipamento(): string;

    public function fechar(): void;
}

/**
 * Falha de comunicacao com a OLT, ja classificada. O tipo decide a mensagem, o contador de
 * falhas de login e, mais tarde, a origem da falha de um job (comunicacao_olt).
 */
final class OltFalha extends ZteErro
{
    public const CODIGOS = [
        'conexao'      => 'ZTE-OLT-006',
        'autenticacao' => 'ZTE-OLT-007',
        'timeout'      => 'ZTE-OLT-008',
        'formato'      => 'ZTE-OLT-009',
        'modo_usuario' => 'ZTE-OLT-013',
        'queda'        => 'ZTE-OLT-015',
        'comando'      => 'ZTE-OLT-016',
    ];

    private string $tipo;

    public function __construct(string $tipo, string $detalheTecnico = '')
    {
        $this->tipo = $tipo;
        parent::__construct(self::CODIGOS[$tipo] ?? 'ZTE-OLT-009',
            $detalheTecnico !== '' ? ['tecnico' => mb_substr($detalheTecnico, 0, 300)] : [], null, 502);
    }

    public function tipo(): string
    {
        return $this->tipo;
    }
}

/** Remove da linha tudo o que nao pode chegar a uma CLI: quebra de linha e controle. */
function zte_linha_segura(string $linha): string
{
    if ($linha === '' || preg_match('/[\x00-\x1F\x7F]/', $linha) || strlen($linha) > 200) {
        throw new InvalidArgumentException('Linha de comando invalida para a OLT.');
    }
    return $linha;
}
