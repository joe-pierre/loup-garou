<?php

namespace App\Providers;

use App\Models\Game;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;

class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registry::class, function () {
            $builder = new DefinitionBuilder();

            $builder->addPlaces([
                'waiting',
                'electing_mayor',
                'night',
                'day',
                'finished',
            ]);

            $builder->addTransitions([
                new Transition('start_election',  'waiting',        'electing_mayor'),
                new Transition('start_night',     'electing_mayor', 'night'),
                new Transition('start_day',       'night',          'day'),
                new Transition('continue_night',  'day',            'night'),
                new Transition('finish',          'night',          'finished'),
                new Transition('finish',          'day',            'finished'),
            ]);

            $definition = $builder->build();
            $marking    = new MethodMarkingStore(true, 'status');
            $workflow   = new Workflow($definition, $marking, null, 'game');
            $registry   = new Registry();
            $registry->addWorkflow($workflow, new InstanceOfSupportStrategy(Game::class));

            return $registry;
        });
    }
}
