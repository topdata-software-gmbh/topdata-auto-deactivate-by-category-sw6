<?php declare(strict_types=1);

namespace Topdata\TopdataAutoDeactivateByCategorySW6\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Topdata\TopdataAutoDeactivateByCategorySW6\Service\ConfigurationService;
use Topdata\TopdataAutoDeactivateByCategorySW6\Service\ProductStatusManager;

#[AsCommand(
    name: 'topdata:autodeactivate:sync',
    description: 'Deactivates all existing active products assigned to configured trash categories.'
)]
class DeactivateExistingProductsCommand extends Command
{
    public function __construct(
        private readonly ConfigurationService $configService,
        private readonly ProductStatusManager $productStatusManager,
        private readonly EntityRepository $productRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $trashCategoryIds = $this->configService->getTrashCategoryIds();
        
        if (empty($trashCategoryIds)) {
            $output->writeln('<comment>No trash categories configured. Please configure the plugin first.</comment>');
            return Command::SUCCESS;
        }

        $context = Context::createDefaultContext();
        
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsAnyFilter('categories.id', $trashCategoryIds));
        
        $productIds = $this->productRepository->searchIds($criteria, $context)->getIds();
        
        if (empty($productIds)) {
            $output->writeln('<info>No active products found in trash categories. Everything is clean.</info>');
            return Command::SUCCESS;
        }
        
        $output->writeln(sprintf('<info>Found %d active products in trash categories. Deactivating...</info>', count($productIds)));
        
        $this->productStatusManager->updateProductStatus($productIds, false, $context);
        
        $output->writeln('<info>Successfully deactivated products.</info>');
        
        return Command::SUCCESS;
    }
}
