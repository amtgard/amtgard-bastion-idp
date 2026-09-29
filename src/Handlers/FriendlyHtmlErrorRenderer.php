<?php

declare(strict_types=1);

namespace Amtgard\IdP\Handlers;

use Slim\Handlers\ErrorHandler;
use Throwable;
use Twig\Environment as TwigEnvironment;

final class FriendlyHtmlErrorRenderer
{
    public function __construct(private TwigEnvironment $view)
    {
    }

    public static function attach(ErrorHandler $handler, TwigEnvironment $view): void
    {
        $renderer = new self($view);
        $handler->registerErrorRenderer('text/html', $renderer);
        $handler->setDefaultErrorRenderer('text/html', $renderer);
    }

    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        return $this->view->render('error.twig', [
            'title' => 'Something went wrong',
            'message' => 'We could not complete that request. Please try again or contact an administrator.',
        ]);
    }
}
