<?php declare(strict_types=1);

namespace Topdata\TopdataDiscountSalesCategorySW6\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataDiscountSalesCategorySW6\Service\ConfigurationService;
use Topdata\TopdataDiscountSalesCategorySW6\Service\DiscountProductResolver;
use Topdata\TopdataDiscountSalesCategorySW6\Service\SalesCategoryManager;

#[AsCommand(
    name: 'topdata:discount-sales:sync',
    description: 'Syncs discounted products into the Sales category.'
)]
class SyncSalesCategoryCommand extends Command
{
    public function __construct(
        private readonly ConfigurationService $configService,
        private readonly DiscountProductResolver $discountProductResolver,
        private readonly SalesCategoryManager $salesCategoryManager,
        private readonly EntityRepository $productRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be done without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $context = Context::createDefaultContext();

        $output->writeln('<info>Ensuring Sales category exists...</info>');
        $categoryId = $this->salesCategoryManager->ensureSalesCategoryExists($context);

        if ($categoryId === null) {
            $output->writeln('<error>No Sales category configured and auto-create is disabled. Please configure the plugin first.</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('  Using category ID: <comment>%s</comment>', $categoryId));

        $output->writeln('<info>Querying products with active discounts...</info>');
        $discountedProductIds = $this->discountProductResolver->getDiscountedProductIds();
        $output->writeln(sprintf('  Found <comment>%d</comment> discounted products.', count($discountedProductIds)));

        $currentlyAssignedIds = $this->salesCategoryManager->getProductIdsInCategory($categoryId, $context);
        $output->writeln(sprintf('  Currently <comment>%d</comment> products in Sales category.', count($currentlyAssignedIds)));

        $discountedSet = array_flip($discountedProductIds);
        $currentSet = array_flip($currentlyAssignedIds);

        $toAdd = [];
        foreach ($discountedProductIds as $id) {
            if (!isset($currentSet[$id])) {
                $toAdd[] = $id;
            }
        }

        $toRemove = [];
        foreach ($currentlyAssignedIds as $id) {
            if (!isset($discountedSet[$id])) {
                $toRemove[] = $id;
            }
        }

        $output->writeln(sprintf('  To add: <comment>%d</comment> products', count($toAdd)));
        $output->writeln(sprintf('  To remove: <comment>%d</comment> products', count($toRemove)));

        if ($dryRun) {
            $output->writeln('<info>Dry-run mode — no changes were made.</info>');
            return Command::SUCCESS;
        }

        if (!empty($toAdd)) {
            $output->writeln('<info>Adding products to Sales category...</info>');
            $this->salesCategoryManager->assignProductsToSalesCategory($toAdd, $categoryId, $context);
        }

        if (!empty($toRemove)) {
            $output->writeln('<info>Removing products from Sales category...</info>');
            $this->salesCategoryManager->removeProductsFromSalesCategory($toRemove, $categoryId, $context);
        }

        $output->writeln('<info>Sync complete.</info>');
        return Command::SUCCESS;
    }
}
