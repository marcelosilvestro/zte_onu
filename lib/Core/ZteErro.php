<?php
/**
 * zte_onu :: excecao de negocio com codigo estavel.
 *
 * Os servicos lancam ZteErro; o roteador AJAX (ajax.php) converte em Resultado com o mesmo
 * codigo. Assim nenhuma regra precisa saber se esta respondendo a web, a CLI ou um teste.
 */
require_once __DIR__ . '/Erros.php';

class ZteErro extends RuntimeException
{
    private string $codigo;
    private array $detalhes;
    private int $http;

    public function __construct(string $codigo, array $detalhes = [], ?string $mensagem = null, int $http = 400)
    {
        $this->codigo   = $codigo;
        $this->detalhes = $detalhes;
        $this->http     = $http;
        parent::__construct($mensagem ?? Erros::mensagem($codigo));
    }

    public function codigo(): string
    {
        return $this->codigo;
    }

    public function detalhes(): array
    {
        return $this->detalhes;
    }

    public function http(): int
    {
        return $this->http;
    }
}
