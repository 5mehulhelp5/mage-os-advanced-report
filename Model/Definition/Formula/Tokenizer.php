<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition\Formula;

/**
 * Splits a formula expression into a flat list of tokens.
 */
class Tokenizer
{
    public const string TYPE_NUMBER = 'NUMBER';
    public const string TYPE_IDENT = 'IDENT';
    public const string TYPE_PLUS = 'PLUS';
    public const string TYPE_MINUS = 'MINUS';
    public const string TYPE_STAR = 'STAR';
    public const string TYPE_SLASH = 'SLASH';
    public const string TYPE_LPAREN = 'LPAREN';
    public const string TYPE_RPAREN = 'RPAREN';
    public const string TYPE_EOF = 'EOF';

    private const array SIMPLE_TOKENS = [
        '+' => self::TYPE_PLUS,
        '-' => self::TYPE_MINUS,
        '*' => self::TYPE_STAR,
        '/' => self::TYPE_SLASH,
        '(' => self::TYPE_LPAREN,
        ')' => self::TYPE_RPAREN,
    ];

    /**
     * Tokenizes the given expression into a flat token list terminated by an EOF token.
     *
     * @param string $expression
     * @throws FormulaException
     * @return array<int, array{type: string, value: string}>
     */
    public function tokenize(string $expression): array
    {
        $tokens = [];
        $length = \strlen($expression);
        $position = 0;

        while ($position < $length) {
            $char = $expression[$position];

            if ($char === ' ') {
                $position++;
                continue;
            }

            if (isset(self::SIMPLE_TOKENS[$char])) {
                $tokens[] = ['type' => self::SIMPLE_TOKENS[$char], 'value' => $char];
                $position++;
                continue;
            }

            if (ctype_digit($char)) {
                $tokens[] = $this->readNumber($expression, $position);
                continue;
            }

            if ($char >= 'a' && $char <= 'z') {
                $start = $position;
                while ($position < $length
                    && (($expression[$position] >= 'a' && $expression[$position] <= 'z')
                        || ctype_digit($expression[$position])
                        || $expression[$position] === '_')
                ) {
                    $position++;
                }
                $tokens[] = ['type' => self::TYPE_IDENT, 'value' => substr($expression, $start, $position - $start)];
                continue;
            }

            throw new FormulaException(
                \sprintf('Unknown character "%s" at position %d in formula "%s".', $char, $position, $expression)
            );
        }

        $tokens[] = ['type' => self::TYPE_EOF, 'value' => ''];

        return $tokens;
    }

    /**
     * Reads a NUMBER token (`\d+(\.\d+)?`) starting at $position, advancing $position past it.
     *
     * @param string $expression
     * @param int $position
     * @return array{type: string, value: string}
     */
    private function readNumber(string $expression, int &$position): array
    {
        $start = $position;
        $length = \strlen($expression);

        while ($position < $length && ctype_digit($expression[$position])) {
            $position++;
        }

        if ($position < $length && $expression[$position] === '.') {
            $position++;
            while ($position < $length && ctype_digit($expression[$position])) {
                $position++;
            }
        }

        return ['type' => self::TYPE_NUMBER, 'value' => substr($expression, $start, $position - $start)];
    }
}
