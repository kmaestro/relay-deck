<?php

declare(strict_types=1);

namespace ApiClient\Script;

use Phplrt\Contracts\Lexer\Channel;
use Phplrt\Lexer\Builder\LexerBuilder;
use Phplrt\Source\StringSource;
use RuntimeException;

/** A deliberately small, safe language for sending and checking API requests. */
final class ScriptLanguage
{
    /** @return array{assertions: list<array{type: 'status'|'body', value: int|string}>, variables: list<array{name: string, path: list<string>}>} */
    public function parse(string $source): array
    {
        if (strlen($source) > 65536) {
            throw new RuntimeException('Scripts are limited to 64 KiB.');
        }

        $tokens = self::lexer()->lex(new StringSource($source));
        $tokens = array_values(array_filter($tokens, static fn ($token): bool => $token->channel !== Channel::EndOfInput));
        $position = 0;
        $next = static function () use (&$position, $tokens) {
            return $tokens[$position++] ?? null;
        };
        $expect = static function (string $name) use (&$position, $tokens, $source) {
            $token = $tokens[$position] ?? null;
            if ($token === null || $token->channel === Channel::Unknown || $token->name !== $name) {
                $line = $token === null ? 1 : substr_count(substr($source, 0, $token->offset), "\n") + 1;
                throw new RuntimeException(sprintf('Line %d: expected %s.', $line, strtolower(str_replace('T_', '', $name))));
            }
            $position++;
            return $token;
        };
        $string = static function () use ($expect): string {
            $raw = $expect('T_STRING')->value;
            $value = json_decode($raw, true);
            if (!is_string($value)) {
                throw new RuntimeException('Invalid string literal. Use double quotes.');
            }
            return $value;
        };

        $assertions = [];
        $variables = [];
        while ($position < count($tokens)) {
            $token = $tokens[$position];
            if ($token->channel === Channel::Unknown) {
                throw new RuntimeException(sprintf('Unexpected character at byte %d.', $token->offset));
            }
            if ($token->name === 'T_ASSERT') {
                $next();
                $kind = $next();
                if ($kind?->name === 'T_STATUS') {
                    $expect('T_EQUAL');
                    $assertions[] = ['type' => 'status', 'value' => (int) $expect('T_NUMBER')->value];
                } elseif ($kind?->name === 'T_BODY') {
                    $expect('T_CONTAINS');
                    $assertions[] = ['type' => 'body', 'value' => $string()];
                } else {
                    throw new RuntimeException('Expected status or body after assert.');
                }
                $expect('T_SEMICOLON');
                if (count($assertions) > 20) {
                    throw new RuntimeException('A script can contain at most 20 assertions.');
                }
                continue;
            }
            if ($token->name === 'T_SET') {
                $next();
                $name = $expect('T_PATH')->value;
                $expect('T_ASSIGN');
                $sourcePath = $expect('T_PATH')->value;
                $expect('T_SEMICOLON');
                $parts = explode('.', $sourcePath);
                if (count($parts) < 3 || array_shift($parts) !== 'response' || array_shift($parts) !== 'json') {
                    throw new RuntimeException('Response variables must use a response.json.field path.');
                }
                $variables[] = ['name' => $name, 'path' => $parts];
                continue;
            }
            throw new RuntimeException(sprintf('Line %d: unexpected token %s.', substr_count(substr($source, 0, $token->offset), "\n") + 1, (string) $token->value));
        }

        if ($assertions === [] && $variables === []) {
            throw new RuntimeException('Add an assertion or a response variable assignment.');
        }
        return ['assertions' => $assertions, 'variables' => $variables];
    }

    private static function lexer(): \Phplrt\Contracts\Lexer\LexerInterface
    {
        static $lexer;
        if ($lexer !== null) {
            return $lexer;
        }
        $builder = new LexerBuilder();
        $builder->addValue('assert', 'T_ASSERT');
        $builder->addValue('status', 'T_STATUS');
        $builder->addValue('body', 'T_BODY');
        $builder->addValue('contains', 'T_CONTAINS');
        $builder->addValue('set', 'T_SET');
        $builder->addPattern('"(?:[^"\\\\]|\\\\.)*"', 'T_STRING');
        $builder->addPattern('[0-9]++', 'T_NUMBER');
        $builder->addValue('==', 'T_EQUAL');
        $builder->addValue('=', 'T_ASSIGN');
        $builder->addPattern('[A-Za-z_][A-Za-z0-9_.-]*+', 'T_PATH');
        $builder->addValue(';', 'T_SEMICOLON');
        $builder->addPattern('\\s++')->hide();
        $builder->addPattern('//[^\\n]*+')->hide();
        return $lexer = $builder->build()->toLexer();
    }
}
