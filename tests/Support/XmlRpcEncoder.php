<?php
namespace Funseco\Dedimania\Tests\Support;

/** Pure-PHP stand-in for xmlrpc_encode() (ext-xmlrpc is not on PHP 8 / CI). Builds a <methodResponse>. */
final class XmlRpcEncoder
{
    public static function response(mixed $value): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><methodResponse><params><param><value>'
            . self::value($value) . '</value></param></params></methodResponse>';
    }

    private static function value(mixed $v): string
    {
        if (is_bool($v)) {
            return '<boolean>' . (int) $v . '</boolean>';
        }
        if (is_int($v)) {
            return '<int>' . $v . '</int>';
        }
        if (is_float($v)) {
            return '<double>' . $v . '</double>';
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return '<array><data>' . implode('', array_map(fn ($i) => '<value>' . self::value($i) . '</value>', $v)) . '</data></array>';
            }
            $members = '';
            foreach ($v as $k => $i) {
                $members .= '<member><name>' . htmlspecialchars((string) $k, ENT_XML1) . '</name><value>' . self::value($i) . '</value></member>';
            }

            return '<struct>' . $members . '</struct>';
        }

        return '<string>' . htmlspecialchars((string) $v, ENT_XML1) . '</string>';
    }
}
