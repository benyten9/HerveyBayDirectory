<?php
namespace Perfmatters\PMCS;

if(!defined('ABSPATH')) {
	exit;
}

class PhpGuard
{
    //direct-access guard inserted into stored php, after any declare/namespace/use prologue
    public static function line()
    {
        return "if (!defined('ABSPATH')) { return; } // <PMCS Guard>";
    }

    //strip the open tag and guard so the editor and export show the original snippet
    public static function strip($code)
    {
        $code = preg_replace('/^<\?php\R?/', '', $code, 1);
        $line = preg_quote(self::line(), '/');

        return preg_replace('/^[ \t]*' . $line . '[ \t]*\R?/m', '', $code, 1);
    }

    //wrap snippet code for storage. php keeps the guard after its prologue; other types close php after the guard
    public static function wrap($code, $type = 'php')
    {
        if($type === 'php') {
            return self::prepare($code);
        }

        $code = self::unwrap($code);

        return self::line() . "\n?>\n" . $code;
    }

    //remove the guard and closing tag from a stored html, js, or css body
    public static function unwrap($code)
    {
        $line = preg_quote(self::line(), '/');

        return preg_replace('/^[ \t]*' . $line . '[ \t]*\R\?>[ \t]*\R?/', '', $code, 1);
    }

    //place the direct-access guard after the snippet prologue and return the code to store
    public static function prepare($code)
    {
        $code = self::strip($code);

        return self::insert($code);
    }

    //insert the guard after declare(), namespace, and use imports
    private static function insert($code)
    {
        $open = "<?php\n";
        $tokens = token_get_all($open . $code);
        $count = count($tokens);
        $i = 0;
        $offset = 0;

        if($count > 0 && is_array($tokens[0]) && $tokens[0][0] === T_OPEN_TAG) {
            $offset += strlen($tokens[0][1]);
            $i = 1;
        }

        $insert_at = $offset;
        $seen_namespace = false;

        while($i < $count) {
            $j = self::skip_insignificant_tokens($tokens, $i);

            if($j >= $count) {
                break;
            }

            $token = $tokens[$j];
            $id = is_array($token) ? $token[0] : null;

            if($id === T_DECLARE && !$seen_namespace) {
                $end = self::consume_until_semicolon($tokens, $j);
            }
            elseif($id === T_NAMESPACE && !self::is_namespace_operator($tokens, $j)) {
                $end = self::consume_namespace_declaration($tokens, $j);
                $seen_namespace = true;
            }
            elseif($id === T_USE) {
                $end = self::consume_until_semicolon($tokens, $j);
            }
            else {
                break;
            }

            $offset += self::token_span($tokens, $i, $end);
            $insert_at = $offset;
            $i = $end;
        }

        $position = $insert_at - strlen($open);
        if($position < 0) {
            $position = 0;
        }

        $length = strlen($code);
        if($position > $length) {
            $position = $length;
        }

        $before = substr($code, 0, $position);
        $after = substr($code, $position);
        //textarea posts use \r\n, so either character already counts as the break around the guard
        $lead = ($before !== '' && !self::is_line_break(substr($before, -1))) ? "\n" : '';
        $trail = ($after === '' || !self::is_line_break($after[0])) ? "\n" : '';

        return $before . $lead . self::line() . $trail . $after;
    }

    private static function is_line_break($character)
    {
        return $character === "\n" || $character === "\r";
    }

    private static function token_text($token)
    {
        return is_array($token) ? $token[1] : $token;
    }

    private static function token_span($tokens, $from, $to)
    {
        $length = 0;

        for($index = $from; $index < $to; $index++) {
            $length += strlen(self::token_text($tokens[$index]));
        }

        return $length;
    }

    private static function skip_insignificant_tokens($tokens, $index)
    {
        $count = count($tokens);

        while($index < $count && is_array($tokens[$index]) && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $index++;
        }

        return $index;
    }

    //namespace\function() is the operator, not a declaration
    private static function is_namespace_operator($tokens, $index)
    {
        $next = self::skip_insignificant_tokens($tokens, $index + 1);

        return $next < count($tokens) && is_array($tokens[$next]) && $tokens[$next][0] === T_NS_SEPARATOR;
    }

    private static function consume_namespace_declaration($tokens, $index)
    {
        $count = count($tokens);

        for($index += 1; $index < $count; $index++) {
            $text = self::token_text($tokens[$index]);

            if($text === '{' || $text === ';') {
                return $index + 1;
            }
        }

        return $count;
    }

    private static function consume_until_semicolon($tokens, $index)
    {
        $count = count($tokens);
        $depth = 0;

        for(; $index < $count; $index++) {
            $text = self::token_text($tokens[$index]);

            if($text === '(' || $text === '{' || $text === '[') {
                $depth++;
            }
            elseif($text === ')' || $text === '}' || $text === ']') {
                if($depth > 0) {
                    $depth--;
                }
            }
            elseif($text === ';' && $depth === 0) {
                return $index + 1;
            }
        }

        return $count;
    }
}
