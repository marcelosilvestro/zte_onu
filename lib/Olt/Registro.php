<?php
/**
 * zte_onu :: registro de drivers e fabrica de transporte.
 *
 * Decide QUAL driver fala com uma OLT e COMO (telnet, simulado). A compatibilidade e calculada
 * so a partir do que foi DETECTADO no teste, nunca do que foi digitado no cadastro.
 */
require_once __DIR__ . '/Transporte.php';
require_once __DIR__ . '/TransporteTelnet.php';
require_once __DIR__ . '/Simulado/TransporteFixture.php';
require_once __DIR__ . '/Zte/C320V21/DriverZteC320V21.php';
require_once __DIR__ . '/Simulado/DriverSimulado.php';
require_once __DIR__ . '/../Core/Cofre.php';

final class RegistroOlt
{
    /** @var class-string<DriverOlt>[] */
    public const DRIVERS = [DriverZteC320V21::class];

    /** Protocolos e se estao disponiveis nesta versao. */
    public const PROTOCOLOS = [
        'telnet'   => ['rotulo' => 'Telnet', 'disponivel' => true, 'porta' => 23],
        'ssh'      => ['rotulo' => 'SSH (em breve)', 'disponivel' => false, 'porta' => 22],
        'simulado' => ['rotulo' => 'Simulada (demonstração, sem rede)', 'disponivel' => true, 'porta' => 23],
    ];

    /** @return array<int,array{fabricante:string,modelo:string,driver:string}> para o seletor do cadastro */
    public static function modelos(): array
    {
        $saida = [];
        foreach (self::DRIVERS as $d) {
            $m = $d::manifesto();
            foreach ($m['modelos'] as $modelo) {
                $saida[] = ['fabricante' => $m['fabricante'], 'modelo' => $modelo, 'driver' => $m['nome']];
            }
        }
        return $saida;
    }

    /** @return array<int,array> manifestos de todos os drivers (matriz de compatibilidade) */
    public static function manifestos(): array
    {
        return array_map(fn($d) => $d::manifesto(), self::DRIVERS);
    }

    /** @return class-string<DriverOlt>|null */
    public static function driverPara(string $fabricante, string $modelo): ?string
    {
        foreach (self::DRIVERS as $d) {
            $m = $d::manifesto();
            if (strcasecmp($m['fabricante'], $fabricante) === 0 && in_array(strtoupper($modelo), array_map('strtoupper', $m['modelos']), true)) {
                return $d;
            }
        }
        return null;
    }

    /** @return class-string<DriverOlt>|null */
    public static function driverPorId(?string $id): ?string
    {
        foreach (self::DRIVERS as $d) {
            if ($d::manifesto()['id'] === $id) {
                return $d;
            }
        }
        return null;
    }

    /**
     * validada        modelo do driver + versao detectada entre as testadas
     * somente_leitura modelo do driver, versao nao testada (ou placas com versoes diferentes)
     * nao_suportada   sem driver
     */
    public static function compatibilidade(?string $driver, string $versaoDetectada): string
    {
        if ($driver === null) {
            return 'nao_suportada';
        }
        return in_array($versaoDetectada, $driver::manifesto()['versoes_testadas'], true) ? 'validada' : 'somente_leitura';
    }

    /**
     * Nivel do recurso de upgrade para esta OLT: 'simulado' na OLT de demonstracao (o worker
     * executa uma atualizacao de mentira), senao o nivel declarado no manifesto do driver.
     */
    public static function nivelUpgrade(array $olt): string
    {
        if (($olt['protocolo'] ?? '') === 'simulado') {
            return 'simulado';
        }
        $d = self::driverPorId($olt['driver'] ?? null) ?? self::driverPara((string) $olt['fabricante'], (string) $olt['modelo']);
        return $d === null ? 'indisponivel' : $d::nivel('upgrade_onu');
    }

    /**
     * O driver que fala com esta OLT, ja ligado ao transporte. A OLT simulada usa o DriverSimulado
     * (leitura dos fixtures + atualizacao de mentira); as demais, o driver do modelo.
     */
    public static function criarDriver(array $olt, Transporte $t): DriverOlt
    {
        if (($olt['protocolo'] ?? '') === 'simulado') {
            return new DriverSimulado($t, (int) $olt['id']);
        }
        $d = self::driverPorId($olt['driver'] ?? null) ?? self::driverPara((string) $olt['fabricante'], (string) $olt['modelo']);
        if ($d === null) {
            throw new ZteErro('ZTE-OLT-012');
        }
        return new $d($t);
    }

    /** Monta o transporte de uma linha de tab_zte_olt. A senha sai do cofre so aqui. */
    public static function transporte(array $olt): Transporte
    {
        switch ($olt['protocolo']) {
            case 'simulado':
                return new TransporteFixture();
            case 'telnet':
                $senha = Cofre::ler('olt', (int) $olt['id']);
                if ($senha === null) {
                    throw new ZteErro('ZTE-OLT-003');
                }
                return new TransporteTelnet((string) $olt['host'], (int) $olt['porta'], (string) $olt['usuario'], $senha,
                    Cofre::ler('olt_enable', (int) $olt['id']), (int) $olt['timeout_conexao_s'], (int) $olt['timeout_comando_s']);
            default:
                throw new ZteErro('ZTE-OLT-010');
        }
    }
}
