<?php
/**
 * zte_onu :: envelope unico de resposta.
 *
 *   { ok, data, warnings[], errors[{code,message,details}], request_id }
 *
 * O request_id aparece na resposta, no log e na auditoria: e o fio que liga o que o
 * operador viu na tela ao que o servidor registrou.
 */
require_once __DIR__ . '/Erros.php';

final class Resultado implements JsonSerializable
{
    private static ?string $requestId = null;

    public bool $ok;
    public $data;
    public array $warnings = [];
    public array $errors   = [];

    private function __construct(bool $ok, $data = null)
    {
        $this->ok   = $ok;
        $this->data = $data;
    }

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = 'REQ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        }
        return self::$requestId;
    }

    public static function ok($data = null): self
    {
        return new self(true, $data);
    }

    public static function erro(string $code, array $details = [], ?string $mensagem = null): self
    {
        $r = new self(false, null);
        $r->addErro($code, $details, $mensagem);
        return $r;
    }

    public function addErro(string $code, array $details = [], ?string $mensagem = null): self
    {
        $this->ok = false;
        $this->errors[] = [
            'code'    => $code,
            'message' => $mensagem ?? Erros::mensagem($code),
            'details' => $details,
        ];
        return $this;
    }

    public function addAviso(string $code, array $details = [], ?string $mensagem = null): self
    {
        $this->warnings[] = [
            'code'    => $code,
            'message' => $mensagem ?? Erros::mensagem($code),
            'details' => $details,
        ];
        return $this;
    }

    public function primeiroCodigo(): ?string
    {
        return $this->errors[0]['code'] ?? null;
    }

    public function jsonSerialize(): array
    {
        return [
            'ok'         => $this->ok,
            'data'       => $this->data,
            'warnings'   => $this->warnings,
            'errors'     => $this->errors,
            'request_id' => self::requestId(),
        ];
    }

    /** Resposta AJAX: encerra a requisicao com o envelope em JSON. */
    public function enviar(int $status = 0): void
    {
        if (!headers_sent()) {
            if ($status === 0) {
                $status = $this->ok ? 200 : 400;
            }
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Request-Id: ' . self::requestId());
        }
        echo json_encode($this, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
