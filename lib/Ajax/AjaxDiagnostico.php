<?php
/**
 * zte_onu :: diagnostico e pacote de suporte.
 */
final class AjaxDiagnostico
{
    public static function componentes(array $e): array
    {
        return ['componentes' => Diagnostico::componentes(), 'gerado_em' => date('d/m/Y H:i:s')];
    }

    public static function pacote(array $e): array
    {
        Auditoria::registrar('diagnostico_exportar', 'diagnostico');
        return Diagnostico::pacoteSuporte();
    }
}
