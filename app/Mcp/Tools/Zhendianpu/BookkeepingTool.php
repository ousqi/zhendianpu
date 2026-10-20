<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

abstract class BookkeepingTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $context = request()->attributes->get('mcp.context');
        if (! $context instanceof AccessContext) {
            return Response::error('AUTH_REQUIRED');
        }
        try {
            return Response::structured($this->respond($context, $request));
        } catch (ValidationException $exception) {
            return Response::error(json_encode(['ok' => false, 'code' => 'INVALID_ARGUMENT', 'fields' => $exception->errors(), 'request_id' => $context->requestId], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (HttpException $exception) {
            return Response::error(json_encode(['ok' => false, 'code' => $exception->getStatusCode() === 403 ? 'FORBIDDEN' : 'INVALID_ARGUMENT', 'request_id' => $context->requestId], JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            report($exception);

            return Response::error(json_encode(['ok' => false, 'code' => 'INTERNAL_ERROR', 'request_id' => $context->requestId], JSON_THROW_ON_ERROR));
        }
    }

    /** @return array<string, mixed> */
    abstract protected function respond(AccessContext $context, Request $request): array;

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
