<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition\Formula;

/**
 * Recursive-descent parser producing an AST (as nested arrays) for a formula expression.
 */
class Parser
{
    private readonly Tokenizer $tokenizer;

    /**
     * No `new` in parameter defaults: Magento's DI compiler var_exports default values into
     * generated/metadata, which breaks on objects (__set_state).
     */
    public function __construct()
    {
        $this->tokenizer = new Tokenizer();
    }

    /**
     * Parses a formula expression into an AST (as nested arrays).
     *
     * @param string $expression
     * @throws FormulaException
     * @return array<string, mixed>
     */
    public function parse(string $expression): array
    {
        $tokens = $this->tokenizer->tokenize($expression);
        $position = 0;

        $node = $this->parseExpr($tokens, $position);

        if ($tokens[$position]['type'] !== Tokenizer::TYPE_EOF) {
            throw new FormulaException(
                \sprintf(
                    'Unbalanced parentheses or unexpected token "%s" in formula "%s".',
                    $tokens[$position]['value'],
                    $expression
                )
            );
        }

        return $node;
    }

    /**
     * Parses `term (('+'|'-') term)*`.
     *
     * @param array<int,array{type:string,value:string}> $tokens
     * @param int $position
     * @return array<string, mixed>
     */
    private function parseExpr(array $tokens, int &$position): array
    {
        $node = $this->parseTerm($tokens, $position);

        while ($tokens[$position]['type'] === Tokenizer::TYPE_PLUS
            || $tokens[$position]['type'] === Tokenizer::TYPE_MINUS
        ) {
            $op = $tokens[$position]['type'] === Tokenizer::TYPE_PLUS ? '+' : '-';
            $position++;
            $right = $this->parseTerm($tokens, $position);
            $node = ['type' => 'binary', 'op' => $op, 'left' => $node, 'right' => $right];
        }

        return $node;
    }

    /**
     * Parses `factor (('*'|'/') factor)*`.
     *
     * @param array<int,array{type:string,value:string}> $tokens
     * @param int $position
     * @return array<string, mixed>
     */
    private function parseTerm(array $tokens, int &$position): array
    {
        $node = $this->parseFactor($tokens, $position);

        while ($tokens[$position]['type'] === Tokenizer::TYPE_STAR
            || $tokens[$position]['type'] === Tokenizer::TYPE_SLASH
        ) {
            $op = $tokens[$position]['type'] === Tokenizer::TYPE_STAR ? '*' : '/';
            $position++;
            $right = $this->parseFactor($tokens, $position);
            $node = ['type' => 'binary', 'op' => $op, 'left' => $node, 'right' => $right];
        }

        return $node;
    }

    /**
     * Parses `NUMBER | IDENT | '(' expr ')' | '-' factor`.
     *
     * @param array<int,array{type:string,value:string}> $tokens
     * @param int $position
     * @throws FormulaException
     * @return array<string, mixed>
     */
    private function parseFactor(array $tokens, int &$position): array
    {
        $token = $tokens[$position];

        if ($token['type'] === Tokenizer::TYPE_MINUS) {
            $position++;
            $operand = $this->parseFactor($tokens, $position);

            return ['type' => 'unary_minus', 'operand' => $operand];
        }

        if ($token['type'] === Tokenizer::TYPE_NUMBER) {
            $position++;

            return ['type' => 'number', 'value' => (float) $token['value'], 'raw' => $token['value']];
        }

        if ($token['type'] === Tokenizer::TYPE_LPAREN) {
            $position++;
            $node = $this->parseExpr($tokens, $position);

            if ($tokens[$position]['type'] !== Tokenizer::TYPE_RPAREN) {
                throw new FormulaException('Unbalanced parentheses: expected ")".');
            }
            $position++;

            return $node;
        }

        if ($token['type'] === Tokenizer::TYPE_IDENT) {
            $position++;

            return ['type' => 'ident', 'name' => $token['value']];
        }

        throw new FormulaException(\sprintf('Unexpected token "%s".', $token['value']));
    }
}
