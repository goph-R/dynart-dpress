<?php

namespace Dynart\Dpress\Controller;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\ResponseInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Media\ImageProcessor;
use Dynart\Dpress\Media\MediaStorage;
use Dynart\Dpress\Service\MediaService;

/**
 * Generates a thumbnail the first time somebody asks for it
 *
 * Nothing routes here in the normal case. A template points at
 * `/uploads/2026/08/photo-a1b2c3-thumb.jpg`; if that file exists Apache serves it and PHP never
 * runs. It is only when the file is *missing* that the `!-f` rewrite condition sends the request
 * to the front controller and this generates it, writes it, and serves it. Every request after
 * that is a static file again.
 */
class MediaController extends AbstractController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        protected ResponseInterface $response,
        protected MediaService $media,
        protected MediaStorage $storage,
        protected ImageProcessor $images,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth);
    }

    /**
     * The layout is fixed at year/month/filename, which is why three segments is enough
     */
    #[Route('GET', '/uploads/?/?/?')]
    public function derivative(string $year, string $month, string $fileName): string {
        $requested = $year.'/'.$month.'/'.$fileName;
        [$originalPath, $preset] = $this->parse($requested);
        if ($preset === null) {
            // not a derivative name, so the original is simply missing
            $this->app()->sendError(404);
        }
        $media = $this->media->findByPath($originalPath);
        if ($media === null || $media->isDeleted()) {
            $this->app()->sendError(404);
        }
        try {
            $relative = $this->media->derivative($media, $preset);
        } catch (DpressException $e) {
            $this->app()->sendError(404);
            return '';
        }
        if ($relative === null || !$this->storage->exists($relative)) {
            $this->app()->sendError(404);
        }
        $this->send($this->storage->fullPath($relative), $media->mime_type);
        return '';
    }

    /**
     * Splits `2026/08/photo-a1b2c3-thumb.jpg` into the original path and the preset
     *
     * @return array [original path, preset or null]
     */
    protected function parse(string $requested): array {
        $extension = pathinfo($requested, PATHINFO_EXTENSION);
        if ($extension === '') {
            return [$requested, null];
        }
        $withoutExtension = substr($requested, 0, -(strlen($extension) + 1));
        foreach (array_keys($this->images->presets()) as $preset) {
            $suffix = '-'.$preset;
            if (str_ends_with($withoutExtension, $suffix)) {
                $original = substr($withoutExtension, 0, -strlen($suffix)).'.'.$extension;
                return [$original, $preset];
            }
        }
        return [$requested, null];
    }

    /**
     * Sends the generated file
     *
     * Only ever the second-best path: once written, Apache serves the same bytes without PHP.
     */
    protected function send(string $path, string $mimeType): void {
        $this->response->setHeader('Content-Type', $mimeType);
        $this->response->setHeader('Content-Length', (string)filesize($path));
        $this->response->setHeader('Cache-Control', 'public, max-age=31536000, immutable');
        $this->response->send((string)file_get_contents($path));
        $this->app()->finish();
    }
}
