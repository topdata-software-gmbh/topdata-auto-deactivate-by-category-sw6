<?php declare(strict_types=1);

namespace Topdata\TopdataAutoDeactivateByCategorySW6\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class AdminApiExampleController extends AbstractController
{
    #[Route(
        path: '/api/_action/topdata-auto-deactivate-by-category-sw6/example', 
        name: 'api.action.autodeactivatebycategorysw6.example', 
        methods: ['GET']
    )]
    public function exampleAction(): JsonResponse
    {
        return new JsonResponse(['success' => true]);
    }
}