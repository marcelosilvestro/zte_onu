<?php
/**
 * zte_onu :: inventario pela linha de comando (e, na proxima etapa, pelo worker).
 *
 *   php cli/inventario.php --olt=ID [--completo] [--descobrir] [--conf=ARQ]
 *   php cli/inventario.php --todas  [--completo]
 *
 * Somente leitura na OLT. --descobrir refaz a lista de PONs (sem ela, usa a ultima descoberta;
 * se nunca houve, descobre).
 * Saida: 0 ok, 1 falha em alguma OLT, 3 sem configuracao de banco.
 */
require_once __DIR__ . '/bootstrap.php';

$args = zte_cli_args($argv);
zte_cli_conectar($args);
$usuario = zte_usuario_cli();

if (isset($args['todas'])) {
    $ids = array_map('intval', array_column(Db::todos("SELECT id FROM tab_zte_olt WHERE ativo = 1 AND compatibilidade IN ('validada','somente_leitura')"), 'id'));
} elseif (isset($args['olt'])) {
    $ids = [(int) $args['olt']];
} else {
    fwrite(STDERR, "Use --olt=ID ou --todas\n");
    exit(1);
}

$falhou = false;
foreach ($ids as $id) {
    try {
        $olt = OltServico::linha($id);
        $pons = $olt['pons_detectadas'] ? (json_decode($olt['pons_detectadas'], true) ?: []) : [];
        if (!$pons || isset($args['descobrir'])) {
            $pons = InventarioServico::descobrir($id, $usuario);
        }
        $resumo = [];
        foreach ($pons as $p) {
            $r = InventarioServico::lerPon($id, (int) $p['slot'], (int) $p['pon'], isset($args['completo']), $usuario);
            $resumo[] = $r;
            printf("  %s %d/%d: %d ONUs (%d online), %d novas, %d detalhes lidos%s\n", $olt['nome'], $r['slot'], $r['pon'],
                $r['total'], $r['online'], $r['novas'], $r['detalhes_lidos'], $r['aviso'] ? ' — ' . $r['aviso'] : '');
        }
        $f = InventarioServico::finalizar($id, $resumo, $usuario);
        printf("%s: %d ONUs, %d online, %d offline\n", $olt['nome'], $f['total'], $f['online'], $f['offline']);
    } catch (Throwable $e) {
        $falhou = true;
        Log::excecao('cli.inventario', $e, ['olt' => $id]);
        fwrite(STDERR, "OLT $id: " . $e->getMessage() . "\n");
    }
}
exit($falhou ? 1 : 0);
