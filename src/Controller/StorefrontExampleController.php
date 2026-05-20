<?php declare(strict_types=1);

namespace Topdata\TopdataAutoDeactivateByCategorySW6\Controller;

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[RouteScope(scopes: ['storefront'])]
class StorefrontExampleController extends StorefrontController
{
    #[Route(
        path: '/autodeactivatebycategorysw6/example', 
        name: 'frontend.autodeactivatebycategorysw6.example', 
        methods: ['GET']
    )]
    public function exampleAction(): Response
    {
        return $this->renderStorefront('@TopdataAutoDeactivateByCategorySW6/storefront/example.html.twig', [
            'pluginName' => 'AutoDeactivateByCategorySW6'
        ]);
    }
}