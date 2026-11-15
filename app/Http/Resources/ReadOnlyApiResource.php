<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Deal;
use App\Models\Robot;
use App\Models\RobotNotification;
use App\Models\RobotRun;
use App\Models\StoreProfile;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;

class ReadOnlyApiResource extends JsonResource
{
    private const DEFINITIONS = [
        'categories' => [
            'model' => Category::class,
            'fields' => ['id', 'name', 'slug', 'created_at', 'updated_at'],
            'relations' => [],
        ],
        'deals' => [
            'model' => Deal::class,
            'fields' => [
                'id', 'title', 'description', 'url', 'image', 'price',
                'currency', 'original_price', 'store', 'expires_at',
                'temperature', 'created_at', 'updated_at',
            ],
            'relations' => [
                'user' => ['foreign_key' => 'user_id', 'resource' => 'users'],
                'category' => ['foreign_key' => 'category_id', 'resource' => 'categories'],
            ],
        ],
        'comments' => [
            'model' => Comment::class,
            'fields' => ['id', 'body', 'created_at', 'updated_at'],
            'relations' => [
                'user' => ['foreign_key' => 'user_id', 'resource' => 'users'],
                'deal' => ['foreign_key' => 'deal_id', 'resource' => 'deals'],
            ],
        ],
        'votes' => [
            'model' => Vote::class,
            'fields' => ['id', 'value', 'created_at', 'updated_at'],
            'relations' => [
                'user' => ['foreign_key' => 'user_id', 'resource' => 'users'],
                'deal' => ['foreign_key' => 'deal_id', 'resource' => 'deals'],
            ],
        ],
        'robots' => [
            'model' => Robot::class,
            'fields' => [
                'id', 'name', 'url', 'interval_minutes', 'is_active',
                'last_price', 'last_currency', 'last_status', 'last_run_at',
                'next_run_at', 'selectors', 'overrides', 'snapshot_path',
                'target_price', 'notify_on_drop', 'alerted_at',
                'notify_on_restock', 'created_at', 'updated_at',
            ],
            'relations' => [
                'user' => ['foreign_key' => 'user_id', 'resource' => 'users'],
            ],
        ],
        'robot-runs' => [
            'model' => RobotRun::class,
            'fields' => [
                'id', 'status', 'provider', 'data', 'price', 'error',
                'duration_ms', 'created_at',
            ],
            'relations' => [
                'robot' => ['foreign_key' => 'robot_id', 'resource' => 'robots'],
            ],
        ],
        'store-profiles' => [
            'model' => StoreProfile::class,
            'fields' => [
                'id', 'domain', 'selectors', 'success_count', 'fail_count',
                'is_active', 'created_at', 'updated_at',
            ],
            'relations' => [
                'user' => ['foreign_key' => 'user_id', 'resource' => 'users'],
            ],
        ],
        'robot-notifications' => [
            'model' => RobotNotification::class,
            'fields' => [
                'id', 'type', 'key', 'value', 'previous_value', 'label',
                'price', 'previous_price', 'read_at', 'created_at',
            ],
            'relations' => [
                'robot' => ['foreign_key' => 'robot_id', 'resource' => 'robots'],
                'user' => ['foreign_key' => 'user_id', 'resource' => 'users'],
            ],
        ],
        'users' => [
            'model' => User::class,
            'fields' => ['id', 'name', 'created_at', 'updated_at'],
            'relations' => [],
        ],
    ];

    public function __construct($resource, private readonly string $resourceName)
    {
        parent::__construct($resource);
    }

    public static function definition(string $resource): array
    {
        if (! isset(self::DEFINITIONS[$resource])) {
            throw new InvalidArgumentException('Recurso API desconocido.');
        }

        return self::DEFINITIONS[$resource];
    }

    public function toArray(Request $request): array
    {
        $definition = self::definition($this->resourceName);
        $data = [];

        foreach ($definition['fields'] as $field) {
            $data[$field] = $this->resource->getAttribute($field);
        }

        $apiBase = rtrim((string) config('app.url'), '/').'/api/v1';

        foreach ($definition['relations'] as $name => $relation) {
            $id = $this->resource->getAttribute($relation['foreign_key']);
            $data[$name] = $id === null
                ? null
                : $apiBase.'/'.$relation['resource'].'/'.$id;
        }

        return $data;
    }
}
