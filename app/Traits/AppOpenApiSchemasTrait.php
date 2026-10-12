<?php

declare(strict_types=1);

namespace SesamePortal;

/**
 * OpenAPI components/schemas for the SesamePortal API.
 *
 * Every response schema mirrors a row builder in AppApiTrait (`apiUserRow()`,
 * `apiCameraRow()` and friends) or a payload assembled in the same file. Keep the
 * field names in sync with those functions: tests/openapi_spec_test.php can verify
 * structure, but only this file and the handlers know the field names.
 */
trait AppOpenApiSchemasTrait
{
    /**
     * @return array<string, mixed>
     */
    private static function openApiSchemas(): array
    {
        return [
            'Error' => self::oaErrorSchema(),
            'Pagination' => self::oaPaginationSchema(),

            // Self
            'ApiIndex' => [
                'type' => 'object',
                'required' => ['name', 'version', 'resources'],
                'properties' => [
                    'name' => ['type' => 'string', 'example' => 'SesamePortal API'],
                    'version' => ['type' => 'string', 'example' => 'v1'],
                    'resources' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'User' => self::oaUserSchema(true),
            'UserProfile' => self::oaUserSchema(false, true),
            'UserResponse' => self::oaWrapper(['user' => self::oaRef('User')]),
            'MeResponse' => self::oaWrapper(['user' => self::oaRef('UserProfile')]),
            'UsersResponse' => self::oaListWrapper('users', 'User'),
            'PhoneTokenResult' => [
                'type' => 'object',
                'required' => ['token', 'user_id', 'login', 'role'],
                'properties' => [
                    'token' => ['type' => 'string', 'description' => 'Static token of the matched user; created on first use.'],
                    'user_id' => ['type' => 'integer'],
                    'login' => ['type' => 'string'],
                    'role' => ['type' => 'string', 'enum' => ['user', 'admin']],
                ],
            ],
            'CallbackStartResult' => [
                'type' => 'object',
                'required' => ['ok', 'pending_id', 'callback_phone', 'lifetime_seconds'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'pending_id' => ['type' => 'string', 'description' => 'Opaque id to poll with `/auth/callback/poll` and finish with `/auth/callback/complete`.'],
                    'callback_phone' => ['type' => 'string', 'description' => 'Callback number the user is expected to call, formatted for display.'],
                    'lifetime_seconds' => ['type' => 'integer', 'example' => 120],
                ],
            ],
            'CallbackStatusResult' => [
                'type' => 'object',
                'required' => ['ok', 'status'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'status' => ['type' => 'string', 'enum' => ['pending', 'confirmed', 'completed', 'expired']],
                ],
            ],

            // Access control
            'UserWrite' => self::oaUserWriteSchema(),
            'Group' => self::oaGroupSchema(false),
            'GroupDetail' => self::oaGroupSchema(true),
            'GroupWrite' => self::oaGroupWriteSchema(),
            'GroupResponse' => self::oaWrapper(['group' => self::oaRef('GroupDetail')]),
            'GroupsResponse' => self::oaListWrapper('groups', 'Group'),
            'GroupChildrenResponse' => self::oaWrapper([
                'group' => self::oaRef('Group'),
                'childGroupIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'children' => self::oaListOf('Group'),
            ]),
            'GroupUsersResponse' => self::oaWrapper([
                'group' => self::oaRef('GroupDetail'),
                'userIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'users' => self::oaListOf('User'),
            ]),
            'GroupCamerasResponse' => self::oaWrapper([
                'group' => self::oaRef('GroupDetail'),
                'cameraIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'cameras' => self::oaListOf('Camera'),
            ]),
            'Folder' => self::oaFolderSchema(false),
            'FolderDetail' => self::oaFolderSchema(true),
            'FolderWrite' => [
                'type' => 'object',
                'required' => ['groupId', 'name'],
                'properties' => [
                    'groupId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Owning group; must exist.'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'blocked' => ['type' => 'boolean'],
                ],
            ],
            'FolderResponse' => self::oaWrapper(['folder' => self::oaRef('FolderDetail')]),
            'FoldersResponse' => self::oaListWrapper('folders', 'Folder'),
            'FolderUsersResponse' => self::oaWrapper([
                'folder' => self::oaRef('FolderDetail'),
                'userIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'users' => self::oaListOf('User'),
            ]),
            'FolderCamerasResponse' => self::oaWrapper([
                'folder' => self::oaRef('FolderDetail'),
                'cameraIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'cameras' => self::oaListOf('Camera'),
            ]),
            'FolderUserIdsWrite' => [
                'type' => 'object',
                'required' => ['userIds'],
                'properties' => ['userIds' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]]],
            ],
            'FolderCameraIdsWrite' => [
                'type' => 'object',
                'required' => ['cameraIds'],
                'properties' => ['cameraIds' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]]],
            ],

            // Servers
            'Server' => self::oaServerSchema(false),
            'ServerDetail' => self::oaServerSchema(true),
            'ServerWrite' => [
                'type' => 'object',
                'required' => ['name', 'baseUrl'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'baseUrl' => ['type' => 'string', 'description' => 'Base URL of the SesameDVR server. A trailing slash is stripped.'],
                    'managementToken' => [
                        'type' => 'string',
                        'description' => 'Write-only: stored encrypted, never returned. An empty string clears the token; omitting the key keeps the current one.',
                    ],
                    'blocked' => ['type' => 'boolean'],
                ],
            ],
            'ServerResponse' => self::oaWrapper(['server' => self::oaRef('ServerDetail')]),
            'ServersResponse' => self::oaListWrapper('servers', 'Server'),

            // Cameras
            'Camera' => self::oaCameraSchema(false),
            'CameraDetail' => self::oaCameraSchema(true),
            'CameraWrite' => self::oaCameraWriteSchema(),
            'CameraResponse' => self::oaWrapper(['camera' => self::oaRef('CameraDetail')]),
            'CamerasResponse' => self::oaListWrapper('cameras', 'Camera'),
            'CameraSaveResponse' => self::oaWrapper([
                'camera' => self::oaRef('CameraDetail'),
                'sync' => self::oaDvrPassThrough('Outcome of the DVR stream synchronisation requested by this call.'),
            ]),
            'FavoritesResponse' => self::oaWrapper([
                'cameraIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'cameras' => self::oaListOf('Camera'),
                'pagination' => self::oaRef('Pagination'),
            ]),

            // Video walls
            'VideoWall' => [
                'type' => 'object',
                'required' => ['id', 'userId', 'name', 'rows', 'columns', 'cameraIds'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'userId' => ['type' => 'integer'],
                    'ownerLogin' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'rows' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6],
                    'columns' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6],
                    'cameraIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'createdAt' => ['type' => 'string', 'nullable' => true],
                    'updatedAt' => ['type' => 'string', 'nullable' => true],
                ],
            ],
            'VideoWallWrite' => [
                'type' => 'object',
                'required' => ['name', 'cameraIds'],
                'properties' => [
                    'name' => ['type' => 'string', 'maxLength' => 255],
                    'rows' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6, 'default' => 3],
                    'columns' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6, 'default' => 3],
                    'cameraIds' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer', 'minimum' => 1],
                        'minItems' => 1,
                        'description' => 'Unique camera ids filling the grid; at most `rows * columns` entries. Every id must be visible to the caller.',
                    ],
                ],
            ],
            'VideoWallResponse' => self::oaWrapper(['data' => self::oaRef('VideoWall')]),
            'VideoWallListResponse' => self::oaWrapper([
                'data' => self::oaListOf('VideoWall'),
                'pagination' => self::oaRef('Pagination'),
            ]),

            // Dashboard
            'DashboardResponse' => self::oaWrapper([
                'counts' => [
                    'type' => 'object',
                    'required' => ['users', 'groups', 'cameras', 'servers'],
                    'properties' => [
                        'users' => ['type' => 'integer'],
                        'groups' => ['type' => 'integer'],
                        'cameras' => ['type' => 'integer'],
                        'servers' => ['type' => 'integer'],
                    ],
                ],
                'servers' => self::oaListOf('ServerDetail'),
            ]),
            'MetricsResultsResponse' => [
                'type' => 'object',
                'required' => ['results'],
                'properties' => [
                    'results' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['serverId'],
                            'properties' => [
                                'serverId' => ['type' => 'integer'],
                                'ok' => ['type' => 'boolean'],
                                'message' => ['type' => 'string'],
                            ],
                            'additionalProperties' => true,
                        ],
                    ],
                ],
            ],

            // Audit
            'AuditEvent' => [
                'type' => 'object',
                'required' => ['id', 'action', 'details', 'createdAt'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'actorUserId' => ['type' => 'integer', 'nullable' => true],
                    'actorLogin' => ['type' => 'string', 'nullable' => true],
                    'action' => ['type' => 'string', 'description' => 'Dotted action name, for example `camera.save` or `user.delete`.'],
                    'details' => ['type' => 'string'],
                    'createdAt' => ['type' => 'string'],
                ],
            ],
            'AuditResponse' => self::oaListWrapper('events', 'AuditEvent'),

            // Agents: the portal forwards these payloads to the DVR unchanged, so
            // the shape is defined by the DVR rather than here.
            'AgentCreateWrite' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'Agent identifier chosen on the DVR side.'],
                    'name' => ['type' => 'string', 'description' => 'Defaults to `id`.'],
                    'enabled' => ['type' => 'boolean', 'default' => true],
                    'capabilities' => [
                        'description' => 'Array of capability strings, or a comma/space separated string parsed into one.',
                        'oneOf' => [
                            ['type' => 'array', 'items' => ['type' => 'string']],
                            ['type' => 'string'],
                        ],
                    ],
                    'password' => ['type' => 'string', 'description' => 'Enrollment password; forwarded to the DVR as given.'],
                ],
            ],
            'AgentUpdateWrite' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'enabled' => ['type' => 'boolean'],
                    'capabilities' => [
                        'oneOf' => [
                            ['type' => 'array', 'items' => ['type' => 'string']],
                            ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'AgentCommandWrite' => [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string', 'default' => 'test_camera', 'description' => 'Command understood by the agent.'],
                    'payload' => ['type' => 'object', 'additionalProperties' => true],
                    'timeoutMs' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            'AgentPasswordWrite' => [
                'type' => 'object',
                'properties' => ['password' => ['type' => 'string']],
            ],
            'AgentDetailResponse' => self::oaWrapper([
                'agentId' => ['type' => 'string'],
                'cameras' => self::oaDvrPassThrough('Cameras reported by the agent.'),
                'commands' => self::oaDvrPassThrough('Command catalogue of the agent.'),
                'logs' => self::oaDvrPassThrough('Agent log lines.'),
            ]),

            // Integrations
            'BillingBlockWrite' => [
                'type' => 'object',
                'required' => ['billingId', 'blocked'],
                'properties' => [
                    'billingId' => ['type' => 'string', 'description' => 'Billing identifier of the group to block or unblock.'],
                    'blocked' => ['type' => 'boolean'],
                ],
            ],
            'TokenByPhoneWrite' => [
                'type' => 'object',
                'required' => ['phone'],
                'properties' => [
                    'phone' => ['type' => 'string', 'description' => 'Phone number of the account, normalised to a Russian number.'],
                    'app_key' => ['type' => 'string', 'description' => 'Alternative to the `X-App-Key` header.'],
                ],
            ],
            'CallbackStartWrite' => [
                'type' => 'object',
                'required' => ['phone'],
                'properties' => ['phone' => ['type' => 'string']],
            ],
            'CallbackCompleteWrite' => [
                'type' => 'object',
                'required' => ['pending_id'],
                'properties' => ['pending_id' => ['type' => 'string']],
            ],
            'CallbackWebhookWrite' => [
                'type' => 'object',
                'properties' => [
                    'phone' => [
                        'type' => 'string',
                        'description' => 'Phone number to confirm. When absent, any phone-like number of 8 to 15 digits is picked from anywhere in the body.',
                    ],
                ],
            ],

            // Shared results
            'Ok' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => ['ok' => ['type' => 'boolean', 'example' => true]],
            ],
            'FavoriteResult' => [
                'type' => 'object',
                'required' => ['ok', 'favorite'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'favorite' => ['type' => 'boolean'],
                ],
            ],
            'CameraDeleteResult' => [
                'type' => 'object',
                'required' => ['ok'],
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'dvr' => self::oaDvrPassThrough('Result of the DVR stream delete, or `null` when `purge` was not requested.'),
                ],
            ],
            'TokenResult' => [
                'type' => 'object',
                'required' => ['token'],
                'properties' => [
                    'token' => ['type' => 'string', 'nullable' => true, 'description' => 'The token itself, or `null` when none is issued.'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaErrorSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['error'],
            'properties' => [
                'error' => [
                    'type' => 'object',
                    'required' => ['code', 'message'],
                    'properties' => [
                        'code' => [
                            'type' => 'string',
                            'description' => 'Stable machine-readable code.',
                            'example' => 'not_found',
                            'enum' => [
                                'invalid_json',
                                'missing_pending_id',
                                'unauthorized',
                                'user_not_found',
                                'callback_disabled',
                                'webhook_not_configured',
                                'forbidden',
                                'invalid_token',
                                'not_found',
                                'request_not_found',
                                'no_active_request',
                                'method_not_allowed',
                                'login_exists',
                                'phone_exists',
                                'group_id_exists',
                                'billing_id_exists',
                                'callback_not_configured',
                                'not_confirmed',
                                'request_expired',
                                'csrf_mismatch',
                                'validation_failed',
                                'invalid_phone',
                                'invalid_stream_name',
                                'invalid_video_wall',
                                'rate_limited',
                                'pending_exists',
                                'internal_error',
                                'dvr_delete_failed',
                                'integration_disabled',
                            ],
                        ],
                        'message' => ['type' => 'string', 'description' => 'Human-readable text, not intended for programmatic use.'],
                    ],
                    'description' => 'Some codes add sibling keys: `existingId` on `login_exists`, `phone_exists` and `camera_name_exists`; `existingGroupId` on `billing_id_exists`; `retry_after_seconds` on `rate_limited` and `pending_exists`; `field`, `pattern` and `maxBytes` on `invalid_stream_name`; `reason` on `invalid_video_wall`.',
                    'additionalProperties' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaPaginationSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['total', 'page', 'pageSize'],
            'properties' => [
                'total' => ['type' => 'integer', 'description' => 'Total number of rows matching the filters.'],
                'page' => ['type' => 'integer', 'description' => 'Current page, 1-based.'],
                'pageSize' => ['type' => 'integer', 'description' => 'Page size that was applied, after clamping.'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private static function oaWrapper(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaListWrapper(string $key, string $item): array
    {
        return self::oaWrapper([
            $key => self::oaListOf($item),
            'pagination' => self::oaRef('Pagination'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaDvrPassThrough(string $description): array
    {
        // A DVR passthrough carries whatever the SesameDVR server answered, so the schema
        // only carries the description. No `type`, therefore no `nullable` either: in
        // OpenAPI 3.0 `nullable` is only meaningful next to a type.
        return [
            'description' => $description . ' Shape is defined by the DVR server, not by this portal.',
            'additionalProperties' => true,
        ];
    }

    /**
     * Mirrors apiUserRow(). Admin responses include `adminComment`; the profile of
     * the caller (`me`) returns `folderIds` instead.
     *
     * @return array<string, mixed>
     */
    private static function oaUserSchema(bool $withAdminComment, bool $withFolders = false): array
    {
        $properties = [
            'id' => ['type' => 'integer'],
            'login' => ['type' => 'string'],
            'phone' => ['type' => 'string', 'description' => 'Normalised phone number, or an empty string when not set.'],
            'role' => ['type' => 'string', 'enum' => ['user', 'admin']],
            'blocked' => ['type' => 'boolean'],
            'readOnly' => ['type' => 'boolean'],
            'hasStaticToken' => ['type' => 'boolean', 'description' => 'Whether a static token is issued. The token itself is only returned by the static-token endpoint.'],
            'createdAt' => ['type' => 'string', 'nullable' => true],
            'lastLoginAt' => ['type' => 'string', 'nullable' => true],
        ];
        if ($withAdminComment) {
            $properties['adminComment'] = ['type' => 'string', 'description' => 'Free-form note visible to administrators only.'];
        }
        if ($withFolders) {
            $properties['folderIds'] = ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Folders that define what this user can see.'];
            $properties['archiveHiddenFolderIds'] = ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Subset of folderIds where archive playback is hidden (online-only cameras).'];
        }
        return [
            'type' => 'object',
            'required' => ['id', 'login', 'role', 'blocked', 'hasStaticToken'],
            'properties' => $properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaUserWriteSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['login', 'password'],
            'description' => 'On create `password` is required and must be at least 6 characters. On update an empty or omitted password keeps the current one.',
            'properties' => [
                'login' => ['type' => 'string'],
                'password' => ['type' => 'string', 'minLength' => 6],
                'role' => ['type' => 'string', 'enum' => ['user', 'admin'], 'default' => 'user'],
                'phone' => ['type' => 'string', 'description' => 'Must normalise to a valid Russian number; an empty string clears it.'],
                'blocked' => ['type' => 'boolean'],
                'readOnly' => ['type' => 'boolean'],
                'adminComment' => ['type' => 'string'],
                'folderIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => 'Replaces the folder links of the user when present.',
                ],
                'archiveHiddenFolderIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => 'Subset of folderIds where archive playback is hidden. Omitted keeps the current flags.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaGroupSchema(bool $detailed): array
    {
        $properties = [
            'id' => ['type' => 'integer'],
            'parentGroupId' => ['type' => 'integer', 'nullable' => true],
            'parentGroupName' => ['type' => 'string', 'nullable' => true],
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'billingId' => ['type' => 'string', 'nullable' => true, 'description' => 'External billing identifier, unique across groups.'],
            'blocked' => ['type' => 'boolean'],
            'createdAt' => ['type' => 'string', 'nullable' => true],
        ];
        if ($detailed) {
            $properties['childGroupIds'] = ['type' => 'array', 'items' => ['type' => 'integer']];
            $properties['children'] = self::oaListOf('Group');
            $properties['folderIds'] = ['type' => 'array', 'items' => ['type' => 'integer']];
            $properties['folders'] = self::oaListOf('Folder');
        }
        return [
            'type' => 'object',
            'required' => ['id', 'name', 'blocked'],
            'properties' => $properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaGroupWriteSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Explicit group id on create. Rejected with 422 when it conflicts with an existing id.'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'billingId' => ['type' => 'string', 'description' => 'Letters, digits and - _ . : @ / only; unique across groups.'],
                'blocked' => ['type' => 'boolean'],
                'parentGroupId' => ['type' => 'integer', 'nullable' => true, 'description' => 'Must not create a cycle in the group tree.'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaFolderSchema(bool $detailed): array
    {
        $properties = [
            'id' => ['type' => 'integer'],
            'groupId' => ['type' => 'integer'],
            'groupName' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'blocked' => ['type' => 'boolean'],
            'createdAt' => ['type' => 'string', 'nullable' => true],
        ];
        if ($detailed) {
            $properties['userIds'] = ['type' => 'array', 'items' => ['type' => 'integer']];
            $properties['cameraIds'] = ['type' => 'array', 'items' => ['type' => 'integer']];
        }
        return [
            'type' => 'object',
            'required' => ['id', 'groupId', 'name'],
            'properties' => $properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaServerSchema(bool $detailed): array
    {
        $properties = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'baseUrl' => ['type' => 'string'],
            'blocked' => ['type' => 'boolean'],
            'hasManagementToken' => ['type' => 'boolean', 'description' => 'Whether a management token is stored. The token itself is write-only.'],
            'lastCheckAt' => ['type' => 'string', 'nullable' => true],
            'lastCheckResult' => ['description' => 'Result of the last reachability check, as stored by the portal.', 'nullable' => true],
            'lastMetricsAt' => ['type' => 'string', 'nullable' => true],
            'createdAt' => ['type' => 'string', 'nullable' => true],
        ];
        if ($detailed) {
            $properties['lastMetrics'] = self::oaDvrPassThrough('Last metrics snapshot collected from the DVR.');
        }
        return [
            'type' => 'object',
            'required' => ['id', 'name', 'baseUrl', 'blocked'],
            'properties' => $properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaCameraSchema(bool $detailed): array
    {
        $properties = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'displayName' => ['type' => 'string', 'description' => 'Alias of `name`.'],
            'sourceUrl' => ['type' => 'string'],
            'serverId' => ['type' => 'integer', 'nullable' => true],
            'serverName' => ['type' => 'string', 'nullable' => true],
            'serverSelection' => ['type' => 'string', 'enum' => ['auto', 'manual']],
            'latitude' => ['type' => 'number', 'format' => 'double', 'nullable' => true],
            'longitude' => ['type' => 'number', 'format' => 'double', 'nullable' => true],
            'directionDeg' => ['type' => 'integer', 'description' => 'Direction the camera points at, in degrees.'],
            'viewAngleDeg' => ['type' => 'integer', 'description' => 'Horizontal field of view in degrees.'],
            'retentionDays' => ['type' => 'string', 'description' => 'Archive retention expressed as a duration, for example `7d`.'],
            'archiveEnabled' => ['type' => 'boolean'],
            'webrtcFastStart' => ['type' => 'boolean'],
            'eventArchiveRetentionEnabled' => ['type' => 'boolean'],
            'eventArchiveMaxBytes' => ['type' => 'integer', 'nullable' => true],
            'eventArchiveMaxDuration' => ['type' => 'string', 'nullable' => true],
            'eventArchiveMaxAge' => ['type' => 'string', 'nullable' => true],
            'timelapseEnabled' => ['type' => 'boolean'],
            'timelapseFramesPerHour' => ['type' => 'integer'],
            'timelapseRetentionDays' => ['type' => 'string', 'nullable' => true],
            'timelapsePlaybackFps' => ['type' => 'integer'],
            'directArchiveVideoTimelineRepairMode' => ['type' => 'string', 'nullable' => true],
            'audioCodec' => ['type' => 'string'],
            'dvrControlMode' => ['type' => 'string', 'enum' => ['managed', 'edge_agent']],
            'agentId' => ['type' => 'string', 'nullable' => true],
            'agentCameraId' => ['type' => 'string', 'nullable' => true],
            'onvifEventsRequested' => ['type' => 'boolean'],
            'watermarkEnabled' => ['type' => 'boolean'],
            'watermarkIntensity' => ['type' => 'integer'],
            'blocked' => ['type' => 'boolean'],
            'dvrStreamName' => ['type' => 'string', 'description' => 'Technical stream name on the DVR. Unique per camera name; must match the DVR naming rules.'],
            'streamUnavailable' => ['description' => 'Whether the DVR reports this stream as unavailable.', 'nullable' => true],
            'lastSyncAt' => ['type' => 'string', 'nullable' => true],
            'lastSyncOk' => ['type' => 'boolean', 'nullable' => true],
            'lastSyncMessage' => ['type' => 'string', 'nullable' => true],
            'createdAt' => ['type' => 'string', 'nullable' => true],
            'updatedAt' => ['type' => 'string', 'nullable' => true],
        ];
        if ($detailed) {
            $properties['folderIds'] = ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Folders the camera is linked to, which is what makes it visible to users.'];
        }
        return [
            'type' => 'object',
            'required' => ['id', 'name', 'dvrStreamName', 'blocked'],
            'properties' => $properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oaCameraWriteSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Both `displayName` or `name`, and `dvrStreamName` are derived from each other when only one is given. Managed cameras require `sourceUrl`; `edge_agent` cameras require `serverId`, `agentId` and `agentCameraId`.',
            'properties' => [
                'displayName' => ['type' => 'string', 'description' => 'Human-readable camera name. Unique; a clash answers 409 `camera_name_exists`.'],
                'name' => ['type' => 'string', 'description' => 'Alias of `displayName`.'],
                'dvrStreamName' => ['type' => 'string', 'maxLength' => 128, 'description' => 'Technical stream name on the DVR: must start with a Latin letter or digit and may contain Latin letters, digits, dot, hyphen and underscore.'],
                'sourceUrl' => ['type' => 'string', 'description' => 'Required when `dvrControlMode` is `managed`.'],
                'dvrControlMode' => ['type' => 'string', 'enum' => ['managed', 'edge_agent'], 'default' => 'managed'],
                'serverId' => ['type' => 'integer', 'nullable' => true, 'description' => 'DVR server that owns the stream.'],
                'serverSelection' => ['type' => 'string', 'enum' => ['auto', 'manual'], 'description' => 'With `auto` and no `serverId` the portal picks a random active server.'],
                'agentId' => ['type' => 'string', 'description' => 'Required for `edge_agent` cameras.'],
                'agentCameraId' => ['type' => 'string', 'description' => 'Camera id on the edge agent; required for `edge_agent` cameras.'],
                'latitude' => ['type' => 'number', 'format' => 'double', 'nullable' => true],
                'longitude' => ['type' => 'number', 'format' => 'double', 'nullable' => true],
                'directionDeg' => ['type' => 'integer', 'default' => 0],
                'viewAngleDeg' => ['type' => 'integer', 'default' => 60],
                'retentionDays' => ['type' => 'string', 'default' => '7d'],
                'archiveEnabled' => ['type' => 'boolean', 'default' => true],
                'webrtcFastStart' => ['type' => 'boolean'],
                'eventArchiveRetentionEnabled' => ['type' => 'boolean'],
                'eventArchiveMaxMb' => ['type' => 'integer', 'description' => 'Megabytes; stored and returned as `eventArchiveMaxBytes`.'],
                'eventArchiveMaxDuration' => ['type' => 'string'],
                'eventArchiveMaxAge' => ['type' => 'string'],
                'timelapseEnabled' => ['type' => 'boolean'],
                'timelapseFramesPerHour' => ['type' => 'integer', 'default' => 60],
                'timelapseRetentionDays' => ['type' => 'string'],
                'timelapsePlaybackFps' => ['type' => 'integer', 'default' => 25],
                'directArchiveVideoTimelineRepairMode' => ['type' => 'string', 'nullable' => true],
                'audioCodec' => ['type' => 'string'],
                'onvifEventsRequested' => ['type' => 'boolean'],
                'onvifHost' => ['type' => 'string'],
                'onvifPort' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535, 'default' => 80],
                'onvifUsername' => ['type' => 'string'],
                'onvifPassword' => ['type' => 'string'],
                'watermarkEnabled' => ['type' => 'boolean'],
                'watermarkIntensity' => ['type' => 'integer'],
                'blocked' => ['type' => 'boolean'],
                'folderIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => 'Replaces the folder links of the camera when present.',
                ],
                'sync' => ['type' => 'boolean', 'default' => true, 'description' => 'Synchronise the stream on the DVR after saving. Use `skipSync` to disable.'],
            ],
        ];
    }
}