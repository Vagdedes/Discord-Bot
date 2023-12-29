<?php

use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Thread\Thread;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;

class DiscordAIMessages
{
    private DiscordPlan $plan;
    public ?array $model;
    private array $mentions, $keywords;

    //todo dalle-3 to discord-ai
    //todo sound to discord-ai

    public function __construct(DiscordPlan $plan)
    {
        $this->plan = $plan;
        $this->mentions = array();

        if (!empty($this->plan->channels->getList())) {
            foreach ($this->plan->channels->getList() as $channel) {
                if ($channel->require_mention !== null) {
                    $this->mentions = get_sql_query(
                        BotDatabaseTable::BOT_AI_MENTIONS,
                        null,
                        array(
                            array("deletion_date", null),
                            array("plan_id", $this->plan->planID),
                            null,
                            array("expiration_date", "IS", null, 0),
                            array("expiration_date", ">", get_current_date()),
                            null
                        )
                    );
                    break;
                }
            }
        }
        $this->keywords = get_sql_query(
            BotDatabaseTable::BOT_AI_KEYWORDS,
            null,
            array(
                array("deletion_date", null),
                array("plan_id", $this->plan->planID),
                null,
                array("expiration_date", "IS", null, 0),
                array("expiration_date", ">", get_current_date()),
                null
            )
        );
        $query = get_sql_query(
            BotDatabaseTable::BOT_AI_CHAT_MODEL,
            null,
            array(
                array("plan_id", $this->plan->planID),
                array("deletion_date", null)
            )
        );

        if (!empty($query)) {
            foreach ($query as $row) {
                $apiKey = $row->api_key !== null ? array($row->api_key) :
                    get_keys_from_file("/root/discord_bot/private/credentials/openai_api_key");

                if ($apiKey === null) {
                    global $logger;
                    $logger->logError($this->plan->planID, "Failed to find API key for plan: " . $this->plan->planID);
                } else {
                    $object = new stdClass();
                    $object->chatAI = new ChatAI(
                        $row->model_family,
                        $apiKey[0],
                        DiscordInheritedLimits::MESSAGE_MAX_LENGTH,
                        $row->temperature,
                        $row->frequency_penalty,
                        $row->presence_penalty,
                        $row->completions,
                        $row->top_p,
                    );
                    $object->instructions = array();
                    $childQuery = get_sql_query(
                        BotDatabaseTable::BOT_AI_INSTRUCTIONS,
                        array("instruction_id"),
                        array(
                            array("ai_model_id", $row->id),
                            array("deletion_date", null),
                            null,
                            array("expiration_date", "IS", null, 0),
                            array("expiration_date", ">", get_current_date()),
                            null
                        )
                    );

                    if (!empty($childQuery)) {
                        foreach ($childQuery as $arrayChildKey => $childRow) {
                            $object->instructions[$arrayChildKey] = $childRow->instruction_id;
                        }
                    }
                    $this->model[$row->channel_id ?? 0] = $object;
                }
            }
        } else {
            $this->model = array();
        }
    }

    public function getModel(?int $channelID = null): ?object
    {
        return $channelID !== null
            ? (array_key_exists($channelID, $this->model) ? $this->model[$channelID] : $this?->model[0])
            : $this?->model[0];
    }

    public function getChatAI(?int $channelID = null): ?ChatAI
    {
        return $this->getModel($channelID)?->chatAI;
    }

    public function textAssistance(Message $message,
                                   Member  $member,
                                   string  $messageContent): bool
    {
        global $logger;
        $punishment = $this->plan->moderation->hasPunishment(DiscordPunishment::AI_BLACKLIST, $member->id);
        $channelObj = $this->plan->utilities->getChannel($message->channel);
        $object = $this->plan->instructions->getObject(
            $message->guild,
            $channelObj,
            $message->thread,
            $member,
            $message
        );
        $command = $this->plan->commands->process(
            $message,
            $member
        );

        if ($command !== null) {
            if ($punishment !== null) {
                if ($punishment->notify !== null) {
                    $message->reply(MessageBuilder::new()->setContent(
                        $this->plan->instructions->replace(array($punishment->creation_reason), $object)[0]
                    ));
                }
            } else if ($command instanceof MessageBuilder) {
                $message->reply($command);
            } else {
                $message->reply(MessageBuilder::new()->setContent(
                    $this->plan->instructions->replace(array($command), $object)[0]
                ));
            }
            return true;
        } else if ($this->plan->userTickets->track($message)
            || $this->plan->userTargets->track($message, $object)
            || $this->plan->userQuestionnaire->track($message, $object)
            || $this->plan->countingChannels->track($message)) {
            return true;
        } else {
            $channel = $object->channel;

            if ($channel !== null) {
                $model = $this->getModel($channel->channel_id);

                if ($model !== null) {
                    $chatAI = $model->chatAI;

                    if ($chatAI->exists) {
                        if ($punishment !== null) {
                            if ($punishment->notify !== null) {
                                $message->reply(MessageBuilder::new()->setContent(
                                    $this->plan->instructions->replace(array($punishment->creation_reason), $object)[0]
                                ));
                            }
                        } else {
                            $cooldownKey = array(__METHOD__, $this->plan->planID, $member->id);

                            if (get_key_value_pair($cooldownKey) === null) {
                                set_key_value_pair($cooldownKey, true);
                                if ($member->id != $this->plan->bot->botID) {
                                    if ($channel->require_mention) {
                                        $mention = false;

                                        if (!empty($message->mentions->first())) {
                                            foreach ($message->mentions as $userObj) {
                                                if ($userObj->id == $this->plan->bot->botID) {
                                                    $mention = true;
                                                    break;
                                                }
                                            }

                                            if (!$mention && !empty($this->mentions)) {
                                                foreach ($this->mentions as $alternativeMention) {
                                                    foreach ($message->mentions as $userObj) {
                                                        if ($userObj->id == $alternativeMention->user_id) {
                                                            $mention = true;
                                                            break 2;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    } else {
                                        $mention = true;
                                    }

                                    if (!$mention && !empty($this->keywords)) {
                                        foreach ($this->keywords as $keyword) {
                                            if ($keyword->keyword !== null) {
                                                if (str_contains($messageContent, $keyword->keyword)) {
                                                    $mention = true;
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    $mention = false;
                                }

                                if ($mention) {
                                    $limits = $this->plan->limits->isLimited($message->guild_id, $message->channel_id, $member->id);

                                    if (!empty($limits)) {
                                        foreach ($limits as $limit) {
                                            if ($limit->message !== null) {
                                                $message->reply(MessageBuilder::new()->setContent(
                                                    $this->plan->instructions->replace(array($limit->message), $object)[0]
                                                ));
                                                break;
                                            }
                                        }
                                    } else {
                                        $cacheKey = array(__METHOD__, $this->plan->planID, $member->id, $messageContent);
                                        $cache = get_key_value_pair($cacheKey);

                                        if ($cache !== null) {
                                            $message->reply(MessageBuilder::new()->setContent($cache));
                                        } else {
                                            if ($channel->require_starting_text !== null
                                                && !starts_with($messageContent, $channel->require_starting_text)
                                                || $channel->require_contained_text !== null
                                                && !str_contains($messageContent, $channel->require_contained_text)
                                                || $channel->require_ending_text !== null
                                                && !ends_with($messageContent, $channel->require_ending_text)
                                                || $channel->min_message_length !== null
                                                && strlen($messageContent) < $channel->min_message_length
                                                || $channel->max_message_length !== null
                                                && strlen($messageContent) > $channel->max_message_length) {
                                                if ($channel->failure_message !== null) {
                                                    $message->reply(MessageBuilder::new()->setContent(
                                                        $this->plan->instructions->replace(array($channel->failure_message), $object)[0]
                                                    ));
                                                }
                                                return true;
                                            }
                                            if ($channel->prompt_message !== null) {
                                                $promptMessage = $this->plan->instructions->replace(array($channel->prompt_message), $object)[0];
                                            } else {
                                                $promptMessage = DiscordProperties::DEFAULT_PROMPT_MESSAGE;
                                            }
                                            $threadID = $message->thread?->id;
                                            $message->reply(MessageBuilder::new()->setContent(
                                                $promptMessage
                                            ))->done(function (Message $message)
                                            use (
                                                $object, $messageContent, $member, $chatAI, $model,
                                                $threadID, $cacheKey, $logger, $channel, $channelObj
                                            ) {
                                                $instructions = $this->plan->instructions->build($object, $model->instructions);
                                                $reference = $message->message_reference?->content ?? null;
                                                $reply = $this->rawTextAssistance(
                                                    $member,
                                                    $channelObj,
                                                    $instructions[0],
                                                    $messageContent
                                                    . ($reference === null
                                                        ? ""
                                                        : DiscordProperties::NEW_LINE
                                                        . DiscordProperties::NEW_LINE
                                                        . "Reference Message:"
                                                        . DiscordProperties::NEW_LINE
                                                        . $reference),
                                                );
                                                $modelReply = $reply[2];

                                                if ($channel->debug !== null) {
                                                    if (!empty($instructions[0])) {
                                                        foreach (str_split($instructions[0], DiscordInheritedLimits::MESSAGE_MAX_LENGTH) as $split) {
                                                            $this->plan->utilities->replyMessage(
                                                                $message,
                                                                MessageBuilder::new()->setContent($split)
                                                            );
                                                        }
                                                    } else {
                                                        $this->plan->utilities->replyMessage(
                                                            $message,
                                                            MessageBuilder::new()->setContent("NO MESSAGE")
                                                        );
                                                    }
                                                    if (!empty($instructions[1])) {
                                                        foreach (str_split($instructions[1], DiscordInheritedLimits::MESSAGE_MAX_LENGTH) as $split) {
                                                            $this->plan->utilities->replyMessage(
                                                                $message,
                                                                MessageBuilder::new()->setContent($split)
                                                            );
                                                        }
                                                    } else {
                                                        $this->plan->utilities->replyMessage(
                                                            $message,
                                                            MessageBuilder::new()->setContent("NO DISCLAIMER")
                                                        );
                                                    }
                                                    foreach (str_split(json_encode($modelReply), DiscordInheritedLimits::MESSAGE_MAX_LENGTH) as $split) {
                                                        $this->plan->utilities->replyMessage(
                                                            $message,
                                                            MessageBuilder::new()->setContent($split)
                                                        );
                                                    }
                                                }
                                                if ($reply[0]) {
                                                    $model = $reply[1];
                                                    $assistance = $chatAI->getText($model, $modelReply);

                                                    if ($assistance !== null) {
                                                        $assistance .= $instructions[1];
                                                        $this->plan->conversation->addMessage(
                                                            $message->guild_id,
                                                            $message->channel_id,
                                                            $threadID,
                                                            $member->id,
                                                            $message->id,
                                                            $messageContent,
                                                        );
                                                        $this->plan->conversation->addReply(
                                                            $message->guild_id,
                                                            $message->channel_id,
                                                            $threadID,
                                                            $member->id,
                                                            $message->id,
                                                            $assistance,
                                                            ($modelReply->usage->prompt_tokens * $model->sent_token_cost) + ($modelReply->usage->completion_tokens * $model->received_token_cost),
                                                            $model->currency->code
                                                        );
                                                        set_key_value_pair($cacheKey, $assistance, $channel->message_retention);
                                                    } else {
                                                        $logger->logError($this->plan->planID, "Failed to get text from chat-model for plan: " . $this->planID);
                                                    }
                                                } else {
                                                    $assistance = null;
                                                    $logger->logError($this->plan->planID, $modelReply);
                                                }

                                                if ($assistance === null || $assistance == DiscordProperties::NO_REPLY) {
                                                    if ($channel->failure_message !== null) {
                                                        $this->plan->utilities->editMessage(
                                                            $message,
                                                            $this->plan->instructions->replace(array($channel->failure_message), $object)[0]
                                                        );
                                                    } else if ($channel->debug === null) {
                                                        $this->plan->utilities->deleteMessage($message);
                                                    }
                                                } else {
                                                    $this->plan->utilities->replyMessageInPieces($message, $assistance);
                                                }
                                            });
                                        }
                                    }
                                }
                                if ($channel->message_cooldown !== null) {
                                    set_key_value_pair($cooldownKey, true, $channel->message_cooldown);
                                } else {
                                    clear_memory(array($cooldownKey));
                                }
                            } else if ($channel->cooldown_message !== null
                                && $channel->message_cooldown !== null) {
                                $message->reply(MessageBuilder::new()->setContent(
                                    $this->plan->instructions->replace(array($channel->cooldown_message), $object)[0]
                                ));
                            }
                        }
                    } else {
                        $logger->logError($this->plan->planID, "Failed to find an existent chat-model for plan: " . $this->plan->planID);
                    }
                } else {
                    $logger->logError($this->plan->planID, "Failed to find any chat-model for plan: " . $this->plan->planID);
                }
                return true;
            }
        }
        return false;
    }

    // 1: Success, 2: Model, 3: Reply, 4: Cache
    public function rawTextAssistance(User|Member    $userObject,
                                      Channel|Thread $channel,
                                      string         $instructions, string $user,
                                      ?int           $extraHash = null,
                                      bool           $cacheTime = false,
                                      string         $cooldownMessage = DiscordProperties::DEFAULT_PROMPT_MESSAGE): array
    {
        $useCache = $cacheTime !== false;

        if ($useCache) {
            $simpleCacheKey = array(
                __METHOD__,
                $this->plan->planID,
                $userObject->id,
                $extraHash
            );
            $cache = get_key_value_pair($simpleCacheKey);

            if ($cache !== null) {
                return $cache;
            } else {
                $cacheKey = $simpleCacheKey;
                $cacheKey[] = string_to_integer($instructions);
                $cacheKey[] = string_to_integer($user);
                $cache = get_key_value_pair($cacheKey);

                if ($cache !== null) {
                    return $cache;
                } else {
                    set_key_value_pair($simpleCacheKey, array(true, null, $cooldownMessage, true));
                }
            }
        }
        $hash = overflow_long(overflow_long($this->plan->planID * 31) + (int)($userObject->id));

        if ($extraHash !== null) {
            $hash = overflow_long(overflow_long($hash * 31) + $extraHash);
        }
        $chatAI = $this->getChatAI($this->plan->utilities->getChannel($channel)->id);

        if ($chatAI === null) {
            $outcome = array(false, null, null, false);
            set_key_value_pair($cacheKey, $outcome, $cacheTime);
            clear_memory(array(manipulate_memory_key($simpleCacheKey)));
        } else {
            $outcome = $chatAI->getResult(
                $hash,
                array(
                    "messages" => array(
                        array(
                            "role" => "system",
                            "content" => $instructions
                        ),
                        array(
                            "role" => "user",
                            "content" => $user
                        )
                    )
                )
            );
            if ($useCache) {
                $outcome[3] = true;
                set_key_value_pair($cacheKey, $outcome, $cacheTime);
                clear_memory(array(manipulate_memory_key($simpleCacheKey)));
                $outcome[3] = false;
            }
        }
        return $outcome;
    }

}