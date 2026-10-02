<?php
/**
 * zte_onu :: permissoes por login.
 */
final class AjaxPermissao
{
    public static function assumirAdmin(array $e): array
    {
        $login = Permissao::login();
        Permissao::assumirAdmin($login);
        Config::set('admin_definido_em', date('Y-m-d H:i:s'), $login, true);
        Auditoria::registrar('assumir_admin', 'permissao', null, null, ['login' => $login, 'papeis' => ['admin']]);
        Log::info('permissao.assumir_admin', ['login' => $login]);
        return ['login' => $login, 'papeis' => Permissao::papeis()];
    }

    public static function listar(array $e): array
    {
        $papeis = [];
        foreach (Permissao::PAPEIS as $id => $desc) {
            $papeis[] = ['id' => $id, 'descricao' => $desc];
        }
        return [
            'permissoes' => Permissao::listar(),
            'usuarios'   => Permissao::usuariosMkauth(),
            'papeis'     => $papeis,
            'eu'         => Permissao::login(),
        ];
    }

    public static function definir(array $e): array
    {
        $login = Validar::login($e['login'] ?? '');
        if (!Permissao::loginExisteNoMkauth($login)) {
            throw new ZteErro('ZTE-AUTH-006', ['login' => $login]);
        }
        $papeis = isset($e['papeis']) && is_array($e['papeis']) ? array_map('strval', $e['papeis']) : [];
        [$antes, $depois] = Permissao::definir($login, $papeis, Permissao::login());
        if ($antes !== $depois) {
            Auditoria::registrar('permissao_definir', 'permissao', null,
                ['login' => $login, 'papeis' => $antes], ['login' => $login, 'papeis' => $depois]);
        }
        return ['login' => $login, 'papeis' => $depois];
    }
}
