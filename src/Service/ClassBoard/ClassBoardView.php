<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

use App\Entity\ClassBoard;
use App\Entity\User;
use App\Enum\ClassBoardWidgetType;
use App\Security\FeatureAccess;

/**
 * What the board template draws: each stored widget with the data it reads, and the dock.
 *
 * The board screen and the widget endpoint (a widget added from the dock, or redrawn after its
 * source changed) both go through here, so a widget is drawn the same way whichever way it arrived.
 */
final class ClassBoardView
{
    public function __construct(
        private readonly ClassBoardWidgetData $widgetData,
        private readonly FeatureAccess $featureAccess,
    ) {
    }

    /**
     * @param array<string, mixed> $widget a normalised widget of the layout
     *
     * @return array{widget: array<string, mixed>, type: ClassBoardWidgetType, data: array<string, mixed>}|null
     */
    public function widget(ClassBoard $board, array $widget): ?array
    {
        $type = ClassBoardWidgetType::tryFrom(\is_string($widget['type'] ?? null) ? $widget['type'] : '');
        if (null === $type) {
            return null;
        }

        return [
            'widget' => $widget,
            'type' => $type,
            'data' => $this->widgetData->forWidget($board, $widget),
        ];
    }

    /**
     * @return list<array{widget: array<string, mixed>, type: ClassBoardWidgetType, data: array<string, mixed>}>
     */
    public function widgets(ClassBoard $board): array
    {
        $views = [];
        foreach ($board->getLayout() as $widget) {
            $view = $this->widget($board, $widget);
            if (null !== $view) {
                $views[] = $view;
            }
        }

        return $views;
    }

    /**
     * The dock, family by family. A widget whose features are off for the viewer is not offered at
     * all; one that reads a class is offered but greyed out on a board whose class cannot be read.
     *
     * @return list<array{family: string, labelKey: string, items: list<array{type: ClassBoardWidgetType, enabled: bool}>}>
     */
    public function dock(ClassBoard $board, User $user): array
    {
        $classReadable = ClassBoardWidgetData::OK === $this->widgetData->classState($board);
        $dock = [];
        foreach (ClassBoardWidgetType::dock() as $family => $types) {
            $items = [];
            foreach ($types as $type) {
                if (!$this->offered($type, $user)) {
                    continue;
                }
                $items[] = ['type' => $type, 'enabled' => $classReadable || !$type->readsClass()];
            }
            if ([] !== $items) {
                $dock[] = ['family' => $family, 'labelKey' => $types[0]->family()->labelKey(), 'items' => $items];
            }
        }

        return $dock;
    }

    /**
     * The Mercure topics the page has to read - the host topic of a live contest, for its count of
     * connected students. The controllers turn them into the subscriber cookie, as the projector
     * screen does.
     *
     * @param list<array{widget: array<string, mixed>, type: ClassBoardWidgetType, data: array<string, mixed>}> $views
     *
     * @return list<string>
     */
    public function mercureTopics(array $views): array
    {
        $topics = [];
        foreach ($views as $view) {
            $session = $view['data']['session'] ?? null;
            if (ClassBoardWidgetType::QuizLive === $view['type'] && \is_array($session) && \is_string($session['topic'] ?? null)) {
                $topics[] = $session['topic'];
            }
        }

        return array_values(array_unique($topics));
    }

    public function offered(ClassBoardWidgetType $type, User $user): bool
    {
        foreach ($type->features() as $feature) {
            if (!$this->featureAccess->isEnabled($feature, $user)) {
                return false;
            }
        }

        return true;
    }
}
