<?php

declare(strict_types=1);

namespace Workflow;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use function React\Promise\all;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use Throwable;
use Workflow\Serializers\Y;
use ReflectionClass;

final class ActivityStub
{
    public static function all(iterable $promises): PromiseInterface
    {
        return all([...$promises]);
    }

    public static function make($activity, ...$arguments): PromiseInterface
    {
        $context = WorkflowStub::getContext();

        $log = $context->storedWorkflow->logs()
            ->whereIndex($context->index)
            ->first();

        if ($log) {
            ++$context->index;
            WorkflowStub::setContext($context);
            $result = Y::unserialize($log->result);
            if (
                is_array($result) &&
                array_key_exists('class', $result) &&
                is_subclass_of($result['class'], Throwable::class)
            ) {
                throw new $result['class']($result['message'], (int) $result['code']);
            }
            return resolve($result);
        }

        $connection = Arr::get(
            (new ReflectionClass($activity))->getDefaultProperties(),
            'connection'
        ) ?: Queue::getDefaultDriver();

        if ($connection === 'sync') {
            $result = $activity::dispatchNow($context->index, $context->now, $context->storedWorkflow, ...$arguments);
            $context->storedWorkflow->logs()
                ->create([
                    'index' => $context->index,
                    'now' => $context->now,
                    'class' => $activity,
                    'result' => Y::serialize($result),
                ]);

            ++$context->index;
            WorkflowStub::setContext($context);
            return resolve($result);
        }

        $activity::dispatch($context->index, $context->now, $context->storedWorkflow, ...$arguments);

        ++$context->index;
        WorkflowStub::setContext($context);
        $deferred = new Deferred();
        return $deferred->promise();
    }
}
