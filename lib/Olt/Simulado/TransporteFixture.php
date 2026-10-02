<?php
/**
 * zte_onu :: transporte da OLT simulada. Nenhum byte sai do servidor.
 */
require_once __DIR__ . '/../Transporte.php';
require_once __DIR__ . '/Fixtures.php';

final class TransporteFixture implements Transporte
{
    private bool $aberto = false;
    /** @var string[] linhas executadas, na ordem (os testes conferem) */
    public array $historico = [];

    public function conectar(): void
    {
        $this->aberto = true;
    }

    public function executar(string $linha): string
    {
        if (!$this->aberto) {
            throw new OltFalha('queda', 'executar sem conexao');
        }
        $linha = zte_linha_segura($linha);
        $this->historico[] = $linha;
        return Fixtures::responder($linha);
    }

    public function nomeEquipamento(): string
    {
        return $this->aberto ? Fixtures::NOME_OLT : '';
    }

    public function fechar(): void
    {
        $this->aberto = false;
    }
}
