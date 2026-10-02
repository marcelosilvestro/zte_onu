<?php
/**
 * zte_onu :: consulta da trilha de auditoria.
 */
final class AjaxAuditoria
{
    public static function listar(array $e): array
    {
        $filtro = [];
        // O filtro de acao chega como "acao_filtro": "acao" na query e o nome da OPERACAO do
        // roteador (ajax.php?acao=...) e o filtro vazio a sobrescrevia (02/10: tela quebrada).
        foreach (['usuario' => 'usuario', 'entidade' => 'entidade', 'acao_filtro' => 'acao', 'correlacao' => 'correlacao'] as $param => $campo) {
            $v = Validar::texto($e[$param] ?? '', 64);
            if ($v !== '') {
                $filtro[$campo] = $v;
            }
        }
        foreach (['de', 'ate'] as $campo) {
            $v = trim((string) ($e[$campo] ?? ''));
            if ($v !== '') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    throw new ZteErro('ZTE-SYS-002', ['campo' => $campo]);
                }
                $filtro[$campo] = $v;
            }
        }
        $pagina = Validar::inteiro($e['pagina'] ?? 1, 1, 100000);
        $r = Auditoria::listar($filtro, $pagina, 50);
        foreach ($r['linhas'] as &$l) {
            $l['antes']  = $l['antes']  !== null ? json_decode($l['antes'], true)  : null;
            $l['depois'] = $l['depois'] !== null ? json_decode($l['depois'], true) : null;
        }
        unset($l);
        $r['pagina'] = $pagina;
        $r['por_pagina'] = 50;
        $r['acoes'] = array_column(Db::todos('SELECT DISTINCT acao FROM tab_zte_auditoria ORDER BY acao'), 'acao');
        $r['entidades'] = array_column(Db::todos('SELECT DISTINCT entidade FROM tab_zte_auditoria ORDER BY entidade'), 'entidade');
        return $r;
    }
}
