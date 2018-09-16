<?php
/**
 * Copyright © 2018 Divante, Inc. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace OpenLoyalty\Component\Account\Infrastructure\Notifier;

use Broadway\CommandHandling\CommandBus;
use OpenLoyalty\Component\Account\Domain\ReadModel\PointsTransferDetails;
use OpenLoyalty\Component\Account\Domain\ReadModel\PointsTransferDetailsRepository;
use OpenLoyalty\Component\Webhook\Domain\Command\DispatchWebhook;
use Psr\Log\LoggerInterface;

/**
 * Class ExpirePointsNotifier.
 */
class ExpirePointsNotifier implements ExpirePointsNotifierInterface
{
    private const REQUEST_PACKAGE_SIZE = 1000;

    private const NOTIFICATION_TYPE = 'account.expiring_points_reminder_generated';

    /**
     * @var int
     */
    private $sentNotifications = 0;

    /**
     * @var CommandBus
     */
    private $commandBus;

    /**
     * @var PointsTransferDetailsRepository
     */
    private $pointsTransferDetailsRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CommandBus                      $commandBus
     * @param PointsTransferDetailsRepository $pointsTransferDetailsRepository
     * @param LoggerInterface                 $logger
     */
    public function __construct(
        CommandBus $commandBus,
        PointsTransferDetailsRepository $pointsTransferDetailsRepository,
        LoggerInterface $logger
    ) {
        $this->commandBus = $commandBus;
        $this->pointsTransferDetailsRepository = $pointsTransferDetailsRepository;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function sendNotificationsForPointsExpiringAfter(\DateTimeInterface $dateTime): void
    {
        $pointTransfers = $this->pointsTransferDetailsRepository->findAllActiveAddingTransfersBeforeExpired($dateTime);

        $notifications = [];

        /** @var PointsTransferDetails $pointTransfer */
        foreach ($pointTransfers as $pointTransfer) {
            if (null === $pointTransfer->getExpiresAt()) {
                continue;
            }

            $notifications[] = [
                'customerId' => (string) $pointTransfer->getCustomerId(),
                'customerEmail' => $pointTransfer->getCustomerEmail(),
                'customerPhone' => $pointTransfer->getCustomerPhone(),
                'customerLoyaltyCardNumber' => $pointTransfer->getCustomerLoyaltyCardNumber(),
                'customerFirstName' => $pointTransfer->getCustomerFirstName(),
                'customerLastName' => $pointTransfer->getCustomerLastName(),
                'points' => $pointTransfer->getValue(),
                'pointsWillExpire' => $pointTransfer->getExpiresAt()->format(\DateTime::ATOM),
            ];

            ++$this->sentNotifications;
        }

        $notificationPackages = array_chunk($notifications, self::REQUEST_PACKAGE_SIZE);

        $this->dispatchWebhookRequest($notificationPackages);
    }

    /**
     * {@inheritdoc}
     */
    public function sentNotificationsCount(): int
    {
        return $this->sentNotifications;
    }

    /**
     * @param array $notificationPackages
     */
    private function dispatchWebhookRequest(array $notificationPackages): void
    {
        foreach ($notificationPackages as $package) {
            try {
                $this->commandBus->dispatch(new DispatchWebhook(self::NOTIFICATION_TYPE, $package));
            } catch (\Exception $exception) {
                $this->logger->error(sprintf('Cannot dispatch webhook %s', self::NOTIFICATION_TYPE));
            }
        }
    }
}
