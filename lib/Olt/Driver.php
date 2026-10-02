<?php
/**
 * zte_onu :: contrato de um driver de OLT.
 *
 * Cada driver DECLARA o que sabe fazer (manifesto). O addon nunca chama um recurso que o
 * manifesto nao marca como disponivel para a versao detectada — e e o manifesto que a tela de
 * compatibilidade mostra ao operador.
 *
 * Niveis de um recurso:
 *   validado      comandos e saidas conferidos na OLT real da versao testada
 *   experimental  implementado a partir de saida real, mas ainda nao exercitado de ponta a ponta
 *   indisponivel  nao implementado (comando nao validado) — o addon recusa usar
 */
require_once __DIR__ . '/Transporte.php';

abstract class DriverOlt
{
    public const NIVEIS = ['validado', 'experimental', 'indisponivel'];

    protected Transporte $t;

    public function __construct(Transporte $transporte)
    {
        $this->t = $transporte;
    }

    /**
     * @return array{id:string,nome:string,fabricante:string,modelos:string[],versoes_testadas:string[],
     *               recursos:array<string,string>,comandos:array<string,string>,limitacoes:string[],prerequisitos:string[]}
     */
    abstract public static function manifesto(): array;

    /**
     * Le a identidade do equipamento.
     * @return array{versao:string,versoes:string[],placas:array,identificador:string}
     */
    abstract public function identificar(): array;

    public static function nivel(string $recurso): string
    {
        return static::manifesto()['recursos'][$recurso] ?? 'indisponivel';
    }

    // ---------------------------------------------------------------- atualizacao (assincrona)
    //
    // O upgrade de uma ONU leva minutos (a OLT busca o arquivo no FTP, grava no banco inativo da
    // ONU, ativa e a ONU reinicia). O worker nao fica esperando: inicia, e nos ciclos seguintes
    // consulta o andamento. Por padrao, todo driver RECUSA — so implementa quem tem o
    // procedimento validado na OLT real.

    /**
     * Inicia a atualizacao de UMA ONU. $fw: arquivo, caminho (pasta na visao da OLT), host, porta,
     * usuario, senha (conta da OLT no FTP), versao (alvo).
     * @return array{op_id:string,saida:string}
     */
    public function iniciarUpgrade(int $slot, int $pon, int $onu, array $fw): array
    {
        throw new ZteErro('ZTE-OLT-018', [], null, 409);
    }

    /**
     * Sinal de FALHA vindo da OLT, quando ela informa (a leitura dos bancos da ONU e que decide o
     * resto), e o andamento da transferencia em % quando a OLT mostra. fase: falhou | desconhecido.
     * @return array{fase:string,detalhe:string,saida:string,progresso:?int}
     */
    public function statusUpgrade(int $slot, int $pon, int $onu): array
    {
        return ['fase' => 'desconhecido', 'detalhe' => '', 'saida' => '', 'progresso' => null];
    }

    /**
     * Espaco da area onde a OLT recebe o firmware antes de enviar a ONU. null = o driver nao sabe
     * ler (a verificacao vira aviso, nunca bloqueio inventado).
     * @return array{total:int,livre:int}|null
     */
    public function espacoFlash(): ?array
    {
        return null;
    }

    /**
     * Resumo das atualizacoes na OLT (falhas de download com motivo, posicoes com falha/sucesso).
     * @return array{downloads:array,operating:array,waiting:array,fail:array,success:array}
     */
    public function resumoManual(): array
    {
        return ['downloads' => [], 'operating' => [], 'waiting' => [], 'fail' => [], 'success' => []];
    }

    /** Reinicia a ONU no banco que recebeu a versao nova. @return string saida (mascarada por quem grava) */
    public function ativar(int $slot, int $pon, int $onu): string
    {
        throw new ZteErro('ZTE-OLT-018', [], null, 409);
    }

    /** Confirma a versao ativa como padrao da ONU. */
    public function confirmar(int $slot, int $pon, int $onu): string
    {
        throw new ZteErro('ZTE-OLT-018', [], null, 409);
    }

    /** Interrompe a operacao em curso na ONU. */
    public function abortar(int $slot, int $pon, int $onu): string
    {
        throw new ZteErro('ZTE-OLT-018', [], null, 409);
    }

    /** A versao (com os bancos) de uma ONU: todo driver com upgrade precisa ler. */
    abstract public function versaoSw(int $slot, int $pon, int $onu): array;
}
