<?php
/**
 * zte_onu :: worker — executado pelo cron (/etc/cron.d/zte_onu), uma vez por minuto, como www-data.
 *
 *   php cli/worker.php [--limite=50] [--sem-inventario] [--sem-sincronizacao] [--conf=ARQ]
 *
 * Um ciclo e curto e idempotente: se o anterior ainda estiver rodando, este sai na hora
 * (trava no banco). Escreve uma linha JSON por ciclo na saida (o cron manda para worker.log).
 * Saida: 0 ok, 1 erro no ciclo, 3 sem configuracao de banco.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/Worker/Worker.php';

$args = zte_cli_args($argv);
zte_cli_conectar($args);
Log::configurar(ZTE_DIR_LOGS, 'worker');
Auditoria::configurar('worker', null);
Permissao::configurar('worker');

$r = Worker::executar([
    'limite_s'      => isset($args['limite']) ? max(10, (int) $args['limite']) : 50,
    'inventario'    => !isset($args['sem-inventario']),
    'sincronizacao' => !isset($args['sem-sincronizacao']),
]);
echo json_encode(['ts' => date('c')] + $r, JSON_UNESCAPED_UNICODE), "\n";
exit(!empty($r['erros']) ? 1 : 0);
