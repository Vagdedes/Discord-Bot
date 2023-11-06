<?php

use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;

class DiscordCommands
{
    private DiscordPlan $plan;
    private array $staticCommands, $dynamicCommands;

    public function __construct(DiscordPlan $plan)
    {
        $this->plan = $plan;
        $this->staticCommands = get_sql_query(
            BotDatabaseTable::BOT_COMMANDS,
            null,
            array(
                array("deletion_date", null),
                array("command_reply", "IS NOT", null),
                null,
                array("plan_id", "IS", null, 0),
                array("plan_id", $this->plan->planID),
                null,
                null,
                array("expiration_date", "IS", null, 0),
                array("expiration_date", ">", get_current_date()),
                null
            )
        );
        $this->dynamicCommands = get_sql_query(
            BotDatabaseTable::BOT_COMMANDS,
            null,
            array(
                array("deletion_date", null),
                array("command_reply", null),
                null,
                array("plan_id", "IS", null, 0),
                array("plan_id", $this->plan->planID),
                null,
                null,
                array("expiration_date", "IS", null, 0),
                array("expiration_date", ">", get_current_date()),
                null
            )
        );
    }

    public function process(Message    $message,
                            int|string $serverID, int|string $channelID, int|string $userID): ?string
    {
        if (!empty($this->staticCommands)) {
            $cacheKey = array(__METHOD__, $this->plan->planID, $serverID, $channelID, $userID, $message->content);
            $cache = get_key_value_pair($cacheKey);

            if ($cache !== null) {
                $cooldown = $this->getCooldown($serverID, $channelID, $userID, $cache[0]);

                if ($cooldown[0]) {
                    return $cooldown[1];
                } else {
                    return $cache[1];
                }
            } else {
                foreach ($this->staticCommands as $command) {
                    if (($command->server_id === null || $command->server_id == $serverID)
                        && ($command->channel_id === null || $command->channel_id == $channelID)
                        && ($command->user_id === null || $command->user_id == $userID)
                        && $message->content == ($command->command_placeholder . $command->command_identification)) {
                        $reply = $command->command_reply;
                        set_key_value_pair($cacheKey, array($command, $reply));
                        $this->getCooldown($serverID, $channelID, $userID, $command);
                        return $reply;
                    }
                }
            }
        }
        if (!empty($this->dynamicCommands)) {
            foreach ($this->dynamicCommands as $command) {
                if (($command->server_id === null || $command->server_id == $serverID)
                    && ($command->channel_id === null || $command->channel_id == $channelID)
                    && ($command->user_id === null || $command->user_id == $userID)
                    && starts_with($message->content, $command->command_placeholder . $command->command_identification)) {
                    $arguments = explode($command->argument_separator ?? " ", $message->content);
                    unset($arguments[0]);
                    $argumentSize = sizeof($arguments);

                    switch ($command->command_identification) {
                        case "close-ticket":
                            $this->plan->ticket->close($message->channel);
                            break;
                        case "get-tickets":
                            $arguments = explode($command->argument_separator, $message->content);

                            if ($argumentSize === 0) {
                                $message->reply("Missing user argument.");
                            } else if ($argumentSize > 1) {
                                $message->reply("Too many arguments.");
                            } else {
                                $findUserID = $arguments[1];

                                if (!is_numeric($findUserID)) {
                                    $findUserID = substr($findUserID, 2, -1);

                                    if (!is_numeric($findUserID)) {
                                        $message->reply("Invalid user argument.");
                                        break;
                                    }
                                }
                                $tickets = $this->plan->ticket->get($findUserID, null, 25);

                                if (empty($tickets)) {
                                    $message->reply("No tickets found for user.");
                                } else {
                                    $messageBuilder = MessageBuilder::new();
                                    $messageBuilder->setContent("Showing last 25 tickets of user.");

                                    foreach ($tickets as $ticket) {
                                        $embed = new Embed($this->plan->discord);
                                        $embed->setTitle($ticket->ticket->title);
                                        $embed->setDescription("ID: " . $ticket->id);

                                        foreach ($ticket->key_value_pairs as $ticketProperties) {
                                            $embed->addFieldValues(
                                                strtoupper($ticketProperties->input_key),
                                                "```" . $ticketProperties->input_value . "```"
                                            );
                                            $embed->setTimestamp(strtotime($ticket->creation_date));
                                        }
                                        $messageBuilder->addEmbed($embed);
                                    }
                                    $message->reply($messageBuilder);
                                }
                            }
                            break;
                        default:
                            break;
                    }
                    break;
                }
            }
        }
        return null;
    }

    private function getCooldown(int|string $serverID, int|string $channelID, int|string $userID,
                                 object     $command): array
    {
        if ($command->cooldown_duration !== null) {
            $cacheKey = array(
                __METHOD__, $this->plan->planID, $serverID, $channelID, $userID,
                $command->command_placeholder . $command->command_identification);
            $cache = get_key_value_pair($cacheKey);

            if ($cache !== null) {
                return array(true, $command->cooldown_message);
            } else {
                set_key_value_pair($cacheKey, true, $command->cooldown_duration);
                return array(false, null);
            }
        } else {
            return array(false, null);
        }
    }
}