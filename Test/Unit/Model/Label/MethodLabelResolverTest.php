<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Config as ShippingConfig;
use MageOS\AdvancedReport\Model\Label\PaymentMethodLabelResolver;
use MageOS\AdvancedReport\Model\Label\ShippingMethodLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentMethodLabelResolver::class)]
#[CoversClass(ShippingMethodLabelResolver::class)]
final class MethodLabelResolverTest extends TestCase
{
    public function testItResolvesPaymentAndShippingMethodTitlesFallingBackToCodes(): void
    {
        $helper = $this->createMock(PaymentHelper::class);
        $helper->expects($this->once())->method('getPaymentMethodList')->willReturn(['checkmo' => 'Check / Money order']);
        $carrier = new class() extends AbstractCarrier implements CarrierInterface {
            public function __construct()
            {
            }

            /**
             * @return string
             */
            public function getConfigData($field)
            {
                return 'Flat Rate';
            }

            /**
             * @return array<string, string>
             */
            public function getAllowedMethods()
            {
                return ['flatrate' => 'Fixed'];
            }

            public function collectRates(RateRequest $request)
            {
                return false;
            }
        };
        $config = $this->createMock(ShippingConfig::class);
        $config->expects($this->once())->method('getAllCarriers')->willReturn(['flatrate' => $carrier]);

        $this->assertSame(
            ['checkmo' => 'Check / Money order', 'legacy' => 'legacy'],
            (new PaymentMethodLabelResolver($helper))->resolve(['checkmo', 'legacy'])
        );
        $this->assertSame(
            ['flatrate_flatrate' => 'Flat Rate - Fixed', 'gone_gone' => 'gone_gone'],
            (new ShippingMethodLabelResolver($config))->resolve(['flatrate_flatrate', 'gone_gone'])
        );
    }
}
