<?php

namespace App\Handler;

use App\Entity\StripeTransfer;
use App\Exception\InvalidArgumentException;
use App\Message\ProcessTransferMessage;
use App\Repository\StripeTransferRepository;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use App\Helper\LoggerHelper;


class ProcessTransferHandler implements MessageHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var LoggerHelper
     */
    private $loggerHelper;

    public function __construct(
        StripeClient $stripeClient,
        StripeTransferRepository $stripeTransferRepository,
        LoggerHelper $loggerHelper
    ) {
        $this->stripeClient = $stripeClient;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->loggerHelper = $loggerHelper;
    }

    public function __invoke(ProcessTransferMessage $message)
    {
        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $message->getStripeTransferId()
        ]);
        assert(null !== $transfer);
        assert(StripeTransfer::TRANSFER_CREATED !== $transfer->getStatus());

        $type = $transfer->getType();
        $amount = $transfer->getAmount();
        $currency = $transfer->getCurrency();
        assert(null !== $amount && null !== $currency);

        try {
            $metadata = [ 'miraklId' => $transfer->getMiraklId() ];
            switch ($type) {
                case StripeTransfer::TRANSFER_PRODUCT_ORDER:
                case StripeTransfer::TRANSFER_SERVICE_ORDER:
                case StripeTransfer::TRANSFER_EXTRA_CREDITS:
                    $accountMapping = $transfer->getAccountMapping();
                    assert(null !== $accountMapping);
                    assert(null !== $accountMapping->getStripeAccountId());

                    $metadata['miraklShopId'] = $accountMapping->getMiraklShopId();
                    $response = $this->stripeClient->createTransfer(
                        $currency,
                        $amount,
                        $accountMapping->getStripeAccountId(),
                        $transfer->getTransactionId(),
                        $metadata
                    );
                    break;
                case StripeTransfer::TRANSFER_SUBSCRIPTION:
                case StripeTransfer::TRANSFER_EXTRA_INVOICES:
                    $accountMapping = $transfer->getAccountMapping();
                    assert(null !== $accountMapping);
                    assert(null !== $accountMapping->getStripeAccountId());

                    $metadata['miraklShopId'] = $accountMapping->getMiraklShopId();
                    $response = $this->stripeClient->createTransferFromConnectedAccount(
                        $currency,
                        $amount,
                        $accountMapping->getStripeAccountId(),
                        $metadata
                    );
                    break;
                case StripeTransfer::TRANSFER_REFUND:
                    assert(null !== $transfer->getTransactionId());

                    $response = $this->stripeClient->reverseTransfer(
                        $amount,
                        $transfer->getTransactionId(),
                        $metadata
                    );
                    break;
            }

            if (isset($response->id)) {
                $transfer->setTransferId($response->id);
                $transfer->setStatus(StripeTransfer::TRANSFER_CREATED);
                $transfer->setStatusReason(null);

                $this->loggerHelper->getLogger()->info('Stripe Transfer created',
                    ['miraklId' => $transfer->getMiraklId(),
                     'transferId' => $response->id,
                     'extra'=> ['type'=>$type, 'amount' => $transfer->getAmount()]
                ]);
            }
        }
        catch (ApiErrorException $e) {
            $message = sprintf('Could not create Stripe Transfer: %s.', $e->getMessage());
            $this->logger->error($message, [
                'miraklId' => $transfer->getMiraklId(),
                'amount' => $transfer->getAmount(),
                'stripeErrorCode' => $e->getStripeCode()
            ]);

            if($e->getMessage() != 'Cannot use an uncaptured charge as a source_transaction') {
                $this->loggerHelper->getLogger()->error($message, [
                    'miraklId' => $transfer->getMiraklId(),
                    'extra' => [
                        'stripeErrorCode' => $e->getStripeCode(),
                        'error' => $e->getMessage()
                    ]
                ]);
            }

            $transfer->setStatus(StripeTransfer::TRANSFER_FAILED);
            $transfer->setStatusReason(substr($e->getMessage(), 0, 1024));
        }

        try {
            $this->stripeTransferRepository->flush();
        } catch (\Throwable $e){
            $this->loggerHelper->getLogger()->error($e->getMessage(), [
                'miraklId' => $transfer->getMiraklId(),
                'extra' =>[
                    'stripeErrorCode' => $e->getStripeCode(),
                    'error' => $e->getMessage()
                ]
            ]);
            return false;
        }
    }
}
