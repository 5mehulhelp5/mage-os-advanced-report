<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use MageOS\AdvancedReport\Test\Unit\Helper\XPathTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

final class DiXmlTest extends TestCase
{
    use XPathTestTrait;

    private const string DI_XML_PATH = __DIR__ . '/../../../etc/di.xml';
    private const string EXPRESSION_NAMESPACE = 'MageOS\\AdvancedReport\\Model\\DataSource\\Mysql\\FieldExpression\\';

    public function testItRegistersShipmentCountAndCreditMemoCountProvidersOnTheFieldExpressionResolver(): void
    {
        $xpath = $this->xpath();
        $base = '/config/type[@name="MageOS\\AdvancedReport\\Model\\DataSource\\Mysql\\FieldExpressionResolver"]'
            . '/arguments/argument[@name="providers"]/item';

        self::assertSame(
            self::EXPRESSION_NAMESPACE . 'ShipmentCountExpression',
            trim($this->element($xpath, $base . '[@name="shipment_count"]')->textContent)
        );
        self::assertSame(
            self::EXPRESSION_NAMESPACE . 'CreditMemoCountExpression',
            trim($this->element($xpath, $base . '[@name="credit_memo_count"]')->textContent)
        );
    }

    public function testItIsRegisteredAsTheFirstShipmentWaitExpressionProviderInDiXml(): void
    {
        $item = $this->element(
            $this->xpath(),
            '/config/type[@name="MageOS\\AdvancedReport\\Model\\DataSource\\Mysql\\FieldExpressionResolver"]'
            . '/arguments/argument[@name="providers"]/item[@name="first_shipment_wait"]'
        );

        self::assertSame(self::EXPRESSION_NAMESPACE . 'FirstShipmentWaitExpression', trim($item->textContent));
    }

    public function testItDeclaresTheSalesDocumentCountVirtualTypesWithTheShipmentAndCreditMemoTables(): void
    {
        $xpath = $this->xpath();
        $type = self::EXPRESSION_NAMESPACE . 'SalesDocumentCountExpression';
        $parameters = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            new ReflectionClass($type)->getConstructor()?->getParameters() ?? []
        );

        foreach ([
            'ShipmentCountExpression' => ['sales_shipment', 'sales_shipment_item', false],
            'CreditMemoCountExpression' => ['sales_creditmemo', 'sales_creditmemo_item', true],
        ] as $name => [$documentTable, $itemTable, $excludesCanceled]) {
            $node = $this->element($xpath, '/config/virtualType[@name="' . self::EXPRESSION_NAMESPACE . $name . '"]');
            self::assertSame($type, $node->getAttribute('type'));

            foreach (self::queryNodes($xpath, 'arguments/argument', $node) as $argument) {
                self::assertInstanceOf(DOMElement::class, $argument);
                self::assertContains($argument->getAttribute('name'), $parameters);
            }
            $argumentQuery = static fn (string $argName): string => 'arguments/argument[@name="' . $argName . '"]';
            $documentArgument = $this->element($xpath, $argumentQuery('documentTable'), $node);
            self::assertSame('string', $documentArgument->getAttribute('xsi:type'));
            self::assertSame($documentTable, trim($documentArgument->textContent));
            $itemArgument = $this->element($xpath, $argumentQuery('itemTable'), $node);
            self::assertSame('string', $itemArgument->getAttribute('xsi:type'));
            self::assertSame($itemTable, trim($itemArgument->textContent));

            if (!$excludesCanceled) {
                self::assertSame(0, self::queryNodes($xpath, $argumentQuery('excludedStates'), $node)->length);
                continue;
            }
            $states = $this->element($xpath, $argumentQuery('excludedStates'), $node);
            self::assertSame('array', $states->getAttribute('xsi:type'));
            $item = $this->element($xpath, 'item', $states);
            self::assertSame('const', $item->getAttribute('xsi:type'));
            self::assertSame('Magento\\Sales\\Model\\Order\\Creditmemo::STATE_CANCELED', trim($item->textContent));
        }
    }

    private function xpath(): DOMXPath
    {
        $document = new DOMDocument();
        $document->load(self::DI_XML_PATH);

        return new DOMXPath($document);
    }

    private function element(DOMXPath $xpath, string $query, ?DOMNode $context = null): DOMElement
    {
        $node = self::queryNodes($xpath, $query, $context)->item(0);
        self::assertInstanceOf(DOMElement::class, $node, $query);

        return $node;
    }

    /**
     * Object arguments injected via di.xml must satisfy the constructor parameter type, otherwise
     * the object manager fails at runtime (and admin blocks render empty in default mode).
     */
    #[DataProvider('objectArgumentProvider')]
    public function testItInjectsObjectArgumentsCompatibleWithTheConstructorParameterType(
        string $typeName,
        string $argumentName,
        string $objectClass,
    ): void {
        self::assertTrue(class_exists($typeName), $typeName . ' does not exist');
        $constructor = new ReflectionClass($typeName)->getConstructor();
        self::assertNotNull($constructor, $typeName . ' has no constructor');

        $parameterType = null;
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getName() === $argumentName) {
                $parameterType = $parameter->getType();
            }
        }
        self::assertInstanceOf(ReflectionNamedType::class, $parameterType, $typeName . '::$' . $argumentName);
        if ($parameterType->isBuiltin()) {
            self::assertContains($parameterType->getName(), ['array', 'object', 'mixed']);

            return;
        }

        self::assertTrue(
            is_a($objectClass, $parameterType->getName(), true),
            \sprintf('%s::$%s expects %s, di.xml injects %s', $typeName, $argumentName, $parameterType->getName(), $objectClass)
        );
    }

    /**
     * @return array<string,array{string,string,string}>
     */
    public static function objectArgumentProvider(): array
    {
        $document = new DOMDocument();
        $document->load(self::DI_XML_PATH);
        $xpath = new DOMXPath($document);

        $cases = [];
        foreach (self::queryNodes($xpath, '/config/type/arguments/argument[@xsi:type="object"]') as $node) {
            self::assertInstanceOf(DOMElement::class, $node);
            $type = $node->parentNode?->parentNode;
            self::assertInstanceOf(DOMElement::class, $type);
            $typeName = $type->getAttribute('name');
            $objectClass = preg_replace('/\\\\Proxy$/', '', trim($node->textContent));
            $cases[$typeName . '::' . $node->getAttribute('name')] = [
                $typeName,
                $node->getAttribute('name'),
                (string) $objectClass,
            ];
        }

        return $cases;
    }
}
