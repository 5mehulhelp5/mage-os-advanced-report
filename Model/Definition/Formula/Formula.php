<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition\Formula;

/**
 * Parsed, immutable representation of a derived metric arithmetic formula.
 */
class Formula
{
    /**
     * @param array<string,mixed> $root
     */
    private function __construct(private readonly array $root)
    {
    }

    /**
     * Tokenizes and parses the given expression into a Formula.
     *
     * @param string $expression
     * @throws FormulaException
     */
    public static function fromString(string $expression): self
    {
        return new self((new Parser())->parse($expression));
    }

    /**
     * Metric codes referenced by the formula, in first-seen order, without duplicates.
     *
     * @return string[]
     */
    public function getReferences(): array
    {
        /** @var string[] $references */
        $references = [];
        $this->collectReferences($this->root, $references);

        return array_values(array_unique($references));
    }

    /**
     * Recursively collects identifier names referenced by the given AST node.
     *
     * @param array<string,mixed> $node
     * @param string[] $references
     */
    private function collectReferences(array $node, array &$references): void
    {
        switch ($node['type']) {
            case 'ident':
                $references[] = (string) $node['name'];
                break;
            case 'unary_minus':
                $this->collectReferences($node['operand'], $references);
                break;
            case 'binary':
                $this->collectReferences($node['left'], $references);
                $this->collectReferences($node['right'], $references);
                break;
        }
    }

    /**
     * Evaluates the formula against a map of metric code to value.
     *
     * Returns null if any referenced value is null (or missing) or if a division by zero occurs.
     *
     * @param array<string,int|float|null> $values
     */
    public function evaluate(array $values): ?float
    {
        return $this->evaluateNode($this->root, $values);
    }

    /**
     * Recursively evaluates the given AST node against a map of metric code to value.
     *
     * @param array<string,mixed> $node
     * @param array<string,int|float|null> $values
     * @throws FormulaException
     */
    private function evaluateNode(array $node, array $values): ?float
    {
        switch ($node['type']) {
            case 'number':
                return $node['value'];
            case 'ident':
                $value = $values[$node['name']] ?? null;

                return $value === null ? null : (float) $value;
            case 'unary_minus':
                $operand = $this->evaluateNode($node['operand'], $values);

                return $operand === null ? null : -$operand;
            case 'binary':
                return $this->evaluateBinary($node, $values);
            default:
                throw new FormulaException('Unknown AST node type.');
        }
    }

    /**
     * Evaluates a binary AST node, propagating null operands and guarding division by zero.
     *
     * @param array<string,mixed> $node
     * @param array<string,int|float|null> $values
     * @throws FormulaException
     */
    private function evaluateBinary(array $node, array $values): ?float
    {
        $left = $this->evaluateNode($node['left'], $values);
        $right = $this->evaluateNode($node['right'], $values);

        if ($left === null || $right === null) {
            return null;
        }

        return match ($node['op']) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $right === 0.0 ? null : $left / $right,
            default => throw new FormulaException('Unknown binary operator.'),
        };
    }

    /**
     * Renders the formula back to a fully parenthesized expression, calling $leafRenderer for
     * each metric identifier. Divisions are guarded with NULLIF to avoid SQL division by zero.
     * Numeric literals are rendered verbatim (they are validated by the tokenizer).
     *
     * @param callable(string):string $leafRenderer
     */
    public function render(callable $leafRenderer): string
    {
        return $this->renderNode($this->root, $leafRenderer);
    }

    /**
     * Recursively renders the given AST node, calling $leafRenderer for each identifier.
     *
     * @param array<string,mixed> $node
     * @param callable(string):string $leafRenderer
     * @throws FormulaException
     */
    private function renderNode(array $node, callable $leafRenderer): string
    {
        return match ($node['type']) {
            'number' => $node['raw'],
            'ident' => $leafRenderer($node['name']),
            'unary_minus' => \sprintf('(-%s)', $this->renderNode($node['operand'], $leafRenderer)),
            'binary' => $this->renderBinary($node, $leafRenderer),
            default => throw new FormulaException('Unknown AST node type.'),
        };
    }

    /**
     * Renders a binary AST node, guarding divisions with NULLIF.
     *
     * @param array<string,mixed> $node
     * @param callable(string):string $leafRenderer
     */
    private function renderBinary(array $node, callable $leafRenderer): string
    {
        $left = $this->renderNode($node['left'], $leafRenderer);
        $right = $this->renderNode($node['right'], $leafRenderer);

        if ($node['op'] === '/') {
            return \sprintf('(%s / NULLIF(%s, 0))', $left, $right);
        }

        return \sprintf('(%s %s %s)', $left, $node['op'], $right);
    }
}
