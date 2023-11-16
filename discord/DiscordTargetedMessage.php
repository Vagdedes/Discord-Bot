<?php

use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Thread\Thread;
use Discord\Parts\User\Member;

class DiscordTargetedMessage
{
    private DiscordPlan $plan;
    private array $targets;

    private const
        REFRESH_TIME = "15 seconds",
        AI_HASH = 528937509;

    public function __construct(DiscordPlan $plan)
    {
        $this->plan = $plan;
        $this->targets = get_sql_query(
            BotDatabaseTable::BOT_TARGETED_MESSAGES,
            null,
            array(
                array("plan_id", $plan->planID),
                array("deletion_date", null),
                null,
                array("expiration_date", "IS", null, 0),
                array("expiration_date", ">", get_current_date()),
                null
            )
        );

        foreach ($this->targets as $arrayKey => $target) {
            unset($this->targets[$arrayKey]);
            $this->targets[$target->id] = $target;
            $query = get_sql_query(
                BotDatabaseTable::BOT_TARGETED_MESSAGE_INSTRUCTIONS,
                array("instruction_id"),
                array(
                    array("target_id", $target->id),
                    array("deletion_date", null),
                    null,
                    array("expiration_date", "IS", null, 0),
                    array("expiration_date", ">", get_current_date()),
                    null
                )
            );

            if (!empty($query)) {
                foreach ($query as $arrayChildKey => $row) {
                    $target->instructions[$arrayChildKey] = $row->instruction_id;
                }
            }
            $this->initiate($target);
        }
    }

    private function initiate(object|string|int $query): void
    {
        if (!is_object($query)) {
            if (!empty($this->targets)) {
                foreach ($this->targets as $target) {
                    if ($target->id == $query) {
                        $this->initiate($target);
                        break;
                    }
                }
            }
            return;
        } else if ($query->max_open_general !== null
            && $this->hasMaxOpen($query->id, null, $query->max_open_general)) {
            return;
        }
        $members = null;

        foreach ($this->plan->discord->guilds as $guild) {
            if ($guild->id == $query->server_id) {
                $members = $guild->members;
                break;
            }
        }

        if (empty($members)) {
            return;
        } else {
            $members = $members->toArray();
            set_sql_cache(self::REFRESH_TIME, self::class);
            $removalList = get_sql_query(
                BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
                array("user_id"),
                array(
                    array("target_id", $query->id),
                )
            );

            if (!empty($removalList)) {
                foreach ($removalList as $removal) {
                    unset($members[$removal->user_id]);
                }
            }
            unset($members[$this->plan->botID]);

            if (empty($members)) {
                return;
            }
        }
        $this->checkExpired();
        $date = get_current_date(); // Always first

        for ($i = 0; $i < min(sizeof($members) + 1, DiscordPredictedLimits::RAPID_CHANNEL_DELETIONS); $i++) {
            $member = null;

            while ($member === null) {
                $key = array_rand($members);
                $member = $members[$key];
                unset($members[$key]);
            }
            if ($query->max_open_per_user !== null
                && $this->hasMaxOpen($query->id, $member->user->id, $query->max_open_per_user)
                && ($query->close_oldest_if_max_open === null || !$this->closeOldest($query))) {
                return;
            }
            $message = MessageBuilder::new()->setContent(
                $this->plan->instructions->replace(
                    array($query->create_message),
                    $this->plan->instructions->getObject(
                        $member->guild,
                        null,
                        null,
                        $member
                    )
                )[0]
            );

            while (true) {
                $targetID = random_number(19);

                if (empty(get_sql_query(
                    BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
                    array("target_id"),
                    array(
                        array("target_creation_id", $targetID)
                    ),
                    null,
                    1
                ))) {
                    $insert = array(
                        "target_id" => $query->id,
                        "target_creation_id" => $targetID,
                        "user_id" => $member->user->id,
                        "server_id" => $query->server_id,
                        "creation_date" => $date,
                        "expiration_date" => get_future_date($query->duration),
                    );

                    if ($query->create_channel_category_id !== null) {
                        $rolePermissions = get_sql_query(
                            BotDatabaseTable::BOT_TARGETED_MESSAGE_ROLES,
                            array("allow", "deny", "role_id"),
                            array(
                                array("deletion_date", null),
                                array("target_id", $query->id)
                            )
                        );

                        if (!empty($rolePermissions)) {
                            foreach ($rolePermissions as $arrayKey => $role) {
                                $rolePermissions[$arrayKey] = array(
                                    "id" => $role->role_id,
                                    "type" => "role",
                                    "allow" => empty($role->allow) ? $query->allow_permission : $role->allow,
                                    "deny" => empty($role->deny) ? $query->allow_permission : $role->deny
                                );
                            }
                        }
                        $memberPermissions = array(
                            array(
                                "id" => $member->user->id,
                                "type" => "member",
                                "allow" => $query->allow_permission,
                                "deny" => $query->allow_permission
                            )
                        );
                        $this->plan->utilities->createChannel(
                            $member->guild,
                            Channel::TYPE_TEXT,
                            $query->create_channel_category_id,
                            (empty($query->create_channel_name)
                                ? $this->plan->utilities->getUsername($member->user->id)
                                : $query->create_channel_name)
                            . "-" . $targetID,
                            $query->create_channel_topic,
                            $rolePermissions,
                            $memberPermissions
                        )->done(function (Channel $channel)
                        use ($targetID, $insert, $member, $message, $query) {
                            $insert["channel_id"] = $channel->id;

                            if (sql_insert(BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS, $insert)) {
                                $channel->sendMessage($message);
                            } else {
                                global $logger;
                                $logger->logError(
                                    $this->plan->planID,
                                    "(1) Failed to insert target creation with ID: " . $query->id
                                );
                            }
                        });
                    } else if ($query->create_channel_id !== null) {
                        $channel = $this->plan->discord->getChannel($query->create_channel_id);

                        if ($channel !== null
                            && $channel->guild_id == $query->server_id) {
                            $channel->startThread($message, $targetID)->done(function (Thread $thread)
                            use ($insert, $member, $channel, $message, $query) {
                                $insert["channel_id"] = $channel->id;
                                $insert["created_thread_id"] = $thread->id;

                                if (sql_insert(BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS, $insert)) {
                                    $channel->sendMessage($message);
                                } else {
                                    global $logger;
                                    $logger->logError(
                                        $this->plan->planID,
                                        "(2) Failed to insert target creation with ID: " . $query->id
                                    );
                                }
                                $insert["created_thread_id"] = $channel->guild_id;

                                if (sql_insert(BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS, $insert)) {
                                    $channel->sendMessage($message);
                                } else {
                                    global $logger;
                                    $logger->logError(
                                        $this->plan->planID,
                                        "Failed to insert target creation of user: " . $member->user->id
                                    );
                                }
                            });
                        } else {
                            global $logger;
                            $logger->logError(
                                $this->plan->planID,
                                "Failed to find target channel with ID: " . $query->id
                            );
                        }
                    } else {
                        global $logger;
                        $logger->logError(
                            $this->plan->planID,
                            "(2) Invalid target with ID: " . $query->id
                        );
                    }
                    break;
                }
            }
        }
    }

    public function track(Member $member, Message $message, object $object): bool
    {
        if (strlen($message->content) > 0) {
            $channel = $message->channel;
            set_sql_cache("1 second");
            $query = get_sql_query(
                BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
                array("id", "target_id", "target_creation_id", "created_thread_id",
                    "expiration_date", "deletion_date"),
                array(
                    array("server_id", $channel->guild_id),
                    array("channel_id", $channel->id),
                ),
                array(
                    "DESC",
                    "id"
                ),
                1
            );

            if (!empty($query)) {
                $query = $query[0];

                if ($query->deletion_date !== null) {
                    if ($query->created_thread_id !== null) {
                        $this->plan->utilities->deleteThread(
                            $channel,
                            $query->created_thread_id
                        );
                    } else {
                        $channel->guild->channels->delete($channel);
                    }
                    $this->initiate($query->target_id);
                } else if (get_current_date() > $query->expiration_date) {
                    if (set_sql_query(
                        BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
                        array(
                            "expired" => 1
                        ),
                        array(
                            array("id", $query->id)
                        ),
                        null,
                        1
                    )) {
                        if ($query->created_thread_id !== null) {
                            $this->plan->utilities->deleteThread(
                                $channel,
                                $query->created_thread_id
                            );
                        } else {
                            $channel->guild->channels->delete($channel);
                        }
                        $this->initiate($query->target_id);
                    } else {
                        global $logger;
                        $logger->logError(
                            $this->plan->planID,
                            "(1) Failed to close expired target with ID: " . $query->id
                        );
                    }
                } else {
                    if ($member->id == $this->plan->botID) {
                        sql_insert(
                            BotDatabaseTable::BOT_TARGETED_MESSAGE_MESSAGES,
                            array(
                                "target_creation_id" => $query->target_creation_id,
                                "user_id" => $message->author->id,
                                "message_id" => $message->id,
                                "message_content" => $message->content,
                                "creation_date" => get_current_date(),
                            )
                        );
                    } else {
                        $target = $this->targets[$query->target_id];

                        if ($target->prompt_message !== null) {
                            $promptMessage = $this->plan->instructions->replace(array($target->prompt_message), $object)[0];
                        } else {
                            $promptMessage = DiscordProperties::DEFAULT_PROMPT_MESSAGE;
                        }
                        $message->reply(MessageBuilder::new()->setContent(
                            $promptMessage
                        ))->done(function (Message $message) use ($member, $object, $target, $query) {
                            $reply = $this->plan->ai->rawTextAssistance(
                                $member,
                                $this->plan->instructions->build($object, $target->instructions),
                                $message->content,
                                self::AI_HASH,
                                "1 minute",
                                $target->cooldown_message
                            );

                            if ($reply[0]) {
                                $model = $reply[1];
                                $modelReply = $reply[2];
                                $isString = is_string($modelReply);
                                $assistance = $isString
                                    ? $modelReply
                                    : $this->plan->ai->chatAI->getText($model, $modelReply);

                                if ($assistance !== null) {
                                    $message->edit(MessageBuilder::new()->setContent($assistance));
                                } else {
                                    $message->edit(MessageBuilder::new()->setContent(
                                        $this->plan->instructions->replace(array($target->failure_message), $object)[0]
                                    ));
                                }
                                sql_insert(
                                    BotDatabaseTable::BOT_TARGETED_MESSAGE_MESSAGES,
                                    array(
                                        "target_creation_id" => $query->target_creation_id,
                                        "user_id" => $message->author->id,
                                        "message_id" => $message->id,
                                        "message_content" => $message->content,
                                        "cost" => $isString ? null : ($modelReply->usage->prompt_tokens * $model->sent_token_cost) + ($modelReply->usage->completion_tokens * $model->received_token_cost),
                                        "creation_date" => get_current_date()
                                    )
                                );
                            } else {
                                $message->edit(MessageBuilder::new()->setContent(
                                    $this->plan->instructions->replace(array($target->failure_message), $object)[0]
                                ));
                            }
                        });
                    }
                    return true;
                }
            }
        }
        return false;
    }

    // Separator

    public function closeByID(int|string $targetID, int|string $userID, ?string $reason = null): ?string
    {
        set_sql_cache("1 second");
        $query = get_sql_query(
            BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
            array("id", "server_id", "channel_id", "created_thread_id",
                "deletion_date", "target_id"),
            array(
                array("target_creation_id", $targetID),
            ),
            null,
            1
        );

        if (empty($query)) {
            return "Not found";
        } else {
            $query = $query[0];

            if ($query->deletion_date !== null) {
                return "Already closed";
            } else {
                try {
                    if (set_sql_query(
                        BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
                        array(
                            "deletion_date" => get_current_date(),
                            "deletion_reason" => empty($reason) ? null : $reason,
                            "deleted_by" => $userID
                        ),
                        array(
                            array("id", $query->id)
                        ),
                        null,
                        1
                    )) {
                        $channel = $this->plan->discord->getChannel($query->channel_id);

                        if ($channel !== null
                            && $channel->guild_id == $query->server_id) {
                            if ($query->created_thread_id !== null) {
                                $this->plan->utilities->deleteThread(
                                    $channel,
                                    $query->created_thread_id,
                                    empty($reason) ? null : $userID . ": " . $reason
                                );
                            } else {
                                $channel->guild->channels->delete(
                                    $channel,
                                    empty($reason) ? null : $userID . ": " . $reason
                                );
                            }
                        }
                        $this->initiate($query->target_id);
                        return null;
                    } else {
                        return "Database query failed";
                    }
                } catch (Throwable $exception) {
                    global $logger;
                    $logger->logError($this->plan->planID, $exception->getMessage());
                    return "(Exception) " . $exception->getMessage();
                }
            }
        }
    }

    public function closeByChannelOrThread(Channel         $channel,
                                           int|string|null $userID = null,
                                           ?string         $reason = null): ?string
    {
        set_sql_cache("1 second");
        $query = get_sql_query(
            BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
            array("id", "created_thread_id", "deletion_date", "target_id"),
            array(
                array("server_id", $channel->guild_id),
                array("channel_id", $channel->id),
            ),
            null,
            1
        );

        if (empty($query)) {
            return "Not found";
        } else {
            $query = $query[0];

            if ($query->deletion_date !== null) {
                return "Already closed";
            } else {
                try {
                    if (set_sql_query(
                        BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
                        array(
                            "deletion_date" => get_current_date(),
                            "deletion_reason" => empty($reason) ? null : $reason,
                            "deleted_by" => $userID
                        ),
                        array(
                            array("id", $query->id)
                        ),
                        null,
                        1
                    )) {
                        if ($query->created_thread_id !== null) {
                            $this->plan->utilities->deleteThread(
                                $channel,
                                $query->created_thread_id,
                                empty($reason) ? null : $userID . ": " . $reason
                            );
                        } else {
                            $channel->guild->channels->delete(
                                $channel,
                                empty($reason) ? null : $userID . ": " . $reason
                            );
                        }
                        $this->initiate($query->target_id);
                        return null;
                    } else {
                        return "Database query failed";
                    }
                } catch (Throwable $exception) {
                    global $logger;
                    $logger->logError($this->plan->planID, $exception->getMessage());
                    return "(Exception) " . $exception->getMessage();
                }
            }
        }
    }

    private function closeOldest(object $target): bool
    {
        $query = get_sql_query(
            BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
            array("id", "server_id", "channel_id", "created_thread_id"),
            array(
                array("deletion_date", null),
                array("expired", null),
                array("target_id", $target->target_id),
            ),
            array(
                "ASC",
                "creation_date"
            ),
            1
        );

        if (!empty($query)) {
            $query = $query[0];

            if (set_sql_query(
                BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
                array(
                    "deletion_date" => get_current_date(),
                ),
                array(
                    array("id", $query->id)
                ),
                null,
                1
            )) {
                $channel = $this->plan->discord->getChannel($query->channel_id);

                if ($channel !== null
                    && $channel->guild_id == $query->server_id) {
                    if ($query->created_thread_id !== null) {
                        $this->plan->utilities->deleteThread(
                            $channel,
                            $query->created_thread_id
                        );
                    } else {
                        $channel->guild->channels->delete($channel);
                    }
                }
                return true;
            } else {
                global $logger;
                $logger->logError($this->plan->planID, "Failed to close oldest target with ID: " . $query->id);
            }
        }
        return false;
    }

    // Separator

    public function getSingle(int|string $targetID): ?object
    {
        $cacheKey = array(__METHOD__, $this->plan->planID, $targetID);
        $cache = get_key_value_pair($cacheKey);

        if ($cache !== null) {
            return $cache === false ? null : $cache;
        } else {
            $query = get_sql_query(
                BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
                null,
                array(
                    array("target_creation_id", $targetID),
                ),
                null,
                1
            );

            if (!empty($query)) {
                $query = $query[0];
                $query->target = $this->targets[$query->target_id];
                $query->messages = get_sql_query(
                    BotDatabaseTable::BOT_TARGETED_MESSAGE_MESSAGES,
                    null,
                    array(
                        array("target_creation_id", $targetID),
                        array("deletion_date", null)
                    ),
                    array(
                        "DESC",
                        "id"
                    )
                );
                rsort($query->messages);
                set_key_value_pair($cacheKey, $query, self::REFRESH_TIME);
                return $query;
            } else {
                set_key_value_pair($cacheKey, false, self::REFRESH_TIME);
                return null;
            }
        }
    }

    public function getMultiple(int|string $userID, int|string|null $pastLookup = null, ?int $limit = null,
                                bool       $messages = true): array
    {
        $cacheKey = array(__METHOD__, $this->plan->planID, $userID, $pastLookup, $limit, $messages);
        $cache = get_key_value_pair($cacheKey);

        if ($cache !== null) {
            return $cache;
        } else {
            $query = get_sql_query(
                BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
                null,
                array(
                    array("user_id", $userID),
                    $pastLookup === null ? "" : array("creation_date", ">", get_past_date($pastLookup)),
                ),
                array(
                    "DESC",
                    "id"
                ),
                $limit
            );

            if (!empty($query)) {
                foreach ($query as $row) {
                    $row->target = $this->targets[$row->target_id];

                    if ($messages) {
                        $row->messages = get_sql_query(
                            BotDatabaseTable::BOT_TARGETED_MESSAGE_MESSAGES,
                            null,
                            array(
                                array("target_creation_id", $row->target_creation_id),
                                array("deletion_date", null)
                            ),
                            array(
                                "DESC",
                                "id"
                            )
                        );
                        rsort($row->messages);
                    }
                }
            }
            set_key_value_pair($cacheKey, $query, self::REFRESH_TIME);
            return $query;
        }
    }

    // Separator

    public function loadSingleTargetMessage(object $target): MessageBuilder
    {
        $this->initiate($target->target_id);
        $messageBuilder = MessageBuilder::new();
        $messageBuilder->setContent("Showing target with ID **" . $target->target_creation_id . "**");

        $embed = new Embed($this->plan->discord);
        $embed->setAuthor($this->plan->utilities->getUsername($target->user_id));

        if (!empty($target->target->title)) {
            $embed->setTitle($target->target->title);
        }
        $embed->setDescription($target->deletion_date === null
            ? ($target->expiration_date !== null && get_current_date() > $target->expiration_date
                ? "Expired on " . get_full_date($target->expiration_date)
                : "Open")
            : "Closed on " . get_full_date($target->deletion_date));
        $messageBuilder->addEmbed($embed);

        if (!empty($target->messages)) {
            $max = DiscordInheritedLimits::MAX_EMBEDS_PER_MESSAGE;

            foreach (array_chunk($target->messages, DiscordInheritedLimits::MAX_EMBEDS_PER_MESSAGE) as $chunk) {
                $embed = new Embed($this->plan->discord);

                foreach ($chunk as $message) {
                    $embed->addFieldValues(
                        $this->plan->utilities->getUsername($message->user_id)
                        . " | " . $message->creation_date,
                        DiscordSyntax::HEAVY_CODE_BLOCK . $message->message_content . DiscordSyntax::HEAVY_CODE_BLOCK
                    );
                }
                $messageBuilder->addEmbed($embed);
                $max--;

                if ($max === 0) {
                    break;
                }
            }
        }
        return $messageBuilder;
    }

    public function loadTargetsMessage(int|string $userID, array $targets): MessageBuilder
    {
        $this->checkExpired();
        $date = get_current_date();
        $messageBuilder = MessageBuilder::new();
        $messageBuilder->setContent("Showing last **" . sizeof($targets) . " targets** of user **" . $this->plan->utilities->getUsername($userID) . "**");

        foreach ($targets as $target) {
            $embed = new Embed($this->plan->discord);

            if (!empty($target->target->title)) {
                $embed->setTitle($target->target->title);
            }
            $embed->setDescription("ID: " . $target->target_creation_id . " | "
                . ($target->deletion_date === null
                    ? ($target->expiration_date !== null && $date > $target->expiration_date
                        ? "Expired on " . get_full_date($target->expiration_date)
                        : "Open")
                    : "Closed on " . get_full_date($target->deletion_date)));
            $messageBuilder->addEmbed($embed);
        }
        return $messageBuilder;
    }

    // Separator

    private function hasMaxOpen(int|string $targetID, int|string|null $userID, int|string $limit): bool
    {
        return sizeof(get_sql_query(
                BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
                array("id"),
                array(
                    array("target_id", $targetID),
                    $userID === null ? "" : array("user_id", $userID),
                    array("deletion_date", null),
                    null,
                    array("expiration_date", "IS", null, 0),
                    array("expiration_date", ">", get_current_date()),
                    null
                ),
                array(
                    "DESC",
                    "id"
                ),
                $limit
            )) == $limit;
    }

    private function checkExpired(): void
    {
        $query = get_sql_query(
            BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
            null,
            array(
                array("deletion_date", null),
                array("expired", null),
                array("expiration_date", "<", get_current_date())
            ),
            array(
                "DESC",
                "id"
            ),
            DiscordPredictedLimits::RAPID_CHANNEL_DELETIONS // Limit so we don't ping Discord too much
        );

        if (!empty($query)) {
            foreach ($query as $row) {
                if (set_sql_query(
                    BotDatabaseTable::BOT_TARGETED_MESSAGE_CREATIONS,
                    array(
                        "expired" => 1
                    ),
                    array(
                        array("id", $row->id)
                    ),
                    null,
                    1
                )) {
                    $channel = $this->plan->discord->getChannel($row->channel_id);

                    if ($channel !== null
                        && $channel->guild_id == $row->server_id) {
                        if ($row->created_thread_id !== null) {
                            $this->plan->utilities->deleteThread(
                                $channel,
                                $row->created_thread_id
                            );
                        } else {
                            $channel->guild->channels->delete($channel);
                        }
                    }
                } else {
                    global $logger;
                    $logger->logError(
                        $this->plan->planID,
                        "(2) Failed to close expired target with ID: " . $row->id
                    );
                }
            }
        }
    }
}