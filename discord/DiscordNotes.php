<?php

use Discord\Builders\Components\Option;
use Discord\Builders\Components\SelectMenu;
use Discord\Builders\Components\TextInput;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\Collection;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Interaction;

class DiscordNotes
{
    private DiscordPlan $plan;

    private const NOT_EXISTS = "This note does not exist or is not available to you.";

    public function __construct(DiscordPlan $plan)
    {
        $this->plan = $plan;
    }

    public function create(Interaction      $interaction,
                           int|float|string $key, ?string $creationReason = null): void
    {
        if ($this->get($interaction, $key, $interaction->user->id) !== null) {
            $this->plan->utilities->acknowledgeMessage(
                $interaction,
                MessageBuilder::new()->setContent(
                    "A note with that key already exists."
                ), true
            );
        } else {
            $this->plan->component->createModal(
                $interaction,
                "Connect Account",
                array(
                    TextInput::new("Title", TextInput::STYLE_SHORT)
                        ->setMinLength(1)->setMaxLength(128)
                        ->setPlaceholder("Give your note a title."),
                    TextInput::new("Description", TextInput::STYLE_PARAGRAPH)
                        ->setMinLength(1)->setMaxLength(2000)
                        ->setPlaceholder("Give your note a description.")
                ),
                null,
                function (Interaction $interaction, Collection $components) use ($key, $creationReason) {
                    if (!$this->plan->component->hasCooldown($interaction)) {
                        $components = $components->toArray();
                        $title = array_shift($components)["title"];
                        $description = array_shift($components)["description"];

                        while (true) {
                            $noteID = random_number(19);

                            if (empty(get_sql_query(
                                BotDatabaseTable::BOT_NOTES,
                                array("note_id"),
                                array(
                                    array("note_id", $noteID)
                                ),
                                null,
                                1
                            ))) {
                                if (sql_insert(
                                        BotDatabaseTable::BOT_NOTES,
                                        array(
                                            "note_id" => $noteID,
                                            "note_key" => $key,
                                            "user_id" => $interaction->user->id,
                                            "creation_date" => get_current_date(),
                                            "creation_reason" => $creationReason
                                        )
                                    )
                                    && sql_insert(
                                        BotDatabaseTable::BOT_NOTE_CHANGES,
                                        array(
                                            "note_id" => $noteID,
                                            "user_id" => $interaction->user->id,
                                            "title" => $title,
                                            "description" => $description,
                                            "creation_date" => get_current_date(),
                                            "creation_reason" => $creationReason
                                        )
                                    )) {
                                    $this->plan->utilities->acknowledgeMessage(
                                        $interaction,
                                        MessageBuilder::new()->setContent(
                                            "Successfully created the note."
                                        ), true
                                    );
                                } else {
                                    global $logger;
                                    $logger->logError(
                                        $this->plan->planID,
                                        "An database error occurred while creating a note for the user: " . $interaction->user->id
                                    );
                                    $this->plan->utilities->acknowledgeMessage(
                                        $interaction,
                                        MessageBuilder::new()->setContent(
                                            "An database error occurred while creating the note."
                                        ), true
                                    );
                                }
                                break;
                            }
                        }
                    }
                },
            );
        }
    }

    public function edit(Interaction      $interaction,
                         int|float|string $key, int|string|null $userID,
                         ?string          $creationReason = null): void
    {
        $object = $this->get($interaction, $key,
            $userID !== null ? $userID : $interaction->user->id);

        if ($object !== null) {
            if ($object->user_id == $interaction->user->id) {
                $proceed = true;
            } else if (!empty($object->participants)) {
                $proceed = false;

                foreach ($object->participants as $participant) {
                    if ($participant->user_id == $interaction->user->id) {
                        $proceed = $participant->write !== null;
                        break;
                    }
                }
            } else {
                $proceed = false;
            }

            if ($proceed) {
                $this->plan->component->createModal(
                    $interaction,
                    "Connect Account",
                    array(
                        TextInput::new("Title", TextInput::STYLE_SHORT)
                            ->setMinLength(1)->setMaxLength(128)
                            ->setPlaceholder("Give your note a title.")
                            ->setValue($object->changes->title),
                        TextInput::new("Description", TextInput::STYLE_PARAGRAPH)
                            ->setMinLength(1)->setMaxLength(2000)
                            ->setPlaceholder("Give your note a description.")
                            ->setValue($object->changes->description)
                    ),
                    null,
                    function (Interaction $interaction, Collection $components) use ($key, $creationReason, $object) {
                        if (!$this->plan->component->hasCooldown($interaction)) {
                            $components = $components->toArray();
                            $title = array_shift($components)["title"];
                            $description = array_shift($components)["description"];

                            if (sql_insert(
                                BotDatabaseTable::BOT_NOTE_CHANGES,
                                array(
                                    "user_id" => $interaction->user->id,
                                    "title" => $title,
                                    "description" => $description,
                                    "creation_date" => get_current_date(),
                                    "creation_reason" => $creationReason
                                ),
                            )) {
                                $this->plan->utilities->acknowledgeMessage(
                                    $interaction,
                                    MessageBuilder::new()->setContent(
                                        "Successfully edited the note."
                                    ), true
                                );
                            } else {
                                global $logger;
                                $logger->logError(
                                    $this->plan->planID,
                                    "An database error occurred while editing a note with ID: " . $object->id
                                );
                                $this->plan->utilities->acknowledgeMessage(
                                    $interaction,
                                    MessageBuilder::new()->setContent(
                                        "An database error occurred while editing the note."
                                    ), true
                                );
                            }
                        }
                    },
                );
            } else {
                $this->plan->utilities->acknowledgeMessage(
                    $interaction,
                    MessageBuilder::new()->setContent(
                        "You do not have permission to edit this note."
                    ), true
                );
            }
        } else {
            $this->plan->utilities->acknowledgeMessage(
                $interaction,
                MessageBuilder::new()->setContent(
                    self::NOT_EXISTS
                ), true
            );
        }
    }

    public function delete(Interaction      $interaction,
                           int|float|string $key, int|string|null $userID,
                           ?string          $deletionReason = null): void
    {
        $object = $this->get($interaction, $key,
            $userID !== null ? $userID : $interaction->user->id);

        if ($object !== null) {
            if ($object->user_id == $interaction->user->id) {
                $proceed = true;
            } else if (!empty($object->participants)) {
                $proceed = false;

                foreach ($object->participants as $participant) {
                    if ($participant->user_id == $interaction->user->id) {
                        $proceed = $participant->delete !== null;
                        break;
                    }
                }
            } else {
                $proceed = false;
            }

            if ($proceed) {
                if (set_sql_query(
                    BotDatabaseTable::BOT_NOTES,
                    array(
                        "deletion_date" => get_current_date(),
                        "deletion_reason" => $deletionReason
                    ),
                    array(
                        array("id", $object->id),
                    )
                )) {
                    $this->plan->utilities->acknowledgeMessage(
                        $interaction,
                        MessageBuilder::new()->setContent(
                            "Successfully deleted the note."
                        ), true
                    );
                } else {
                    global $logger;
                    $logger->logError(
                        $this->plan->planID,
                        "An database error occurred while deleting a note with ID: " . $object->id
                    );
                    $this->plan->utilities->acknowledgeMessage(
                        $interaction,
                        MessageBuilder::new()->setContent(
                            "An database error occurred while deleting the note."
                        ), true
                    );
                }
            } else {
                $this->plan->utilities->acknowledgeMessage(
                    $interaction,
                    MessageBuilder::new()->setContent(
                        "You do not have permission to delete this note."
                    ), true
                );
            }
        } else {
            $this->plan->utilities->acknowledgeMessage(
                $interaction,
                MessageBuilder::new()->setContent(
                    self::NOT_EXISTS
                ), true
            );
        }
    }

    public function get(Interaction $interaction, int|float|string $key, int|string|null $userID,
                        int|string  $past = 1): ?object
    {
        $query = get_sql_query(
            BotDatabaseTable::BOT_NOTES,
            null,
            array(
                array("note_key", $key),
                array("user_id", $userID),
                array("server_id", $interaction->guild_id),
                array("deletion_date", null)
            ),
            null,
            1
        );

        if (!empty($query)) {
            $query = $query[0];
            $childQuery = get_sql_query(
                BotDatabaseTable::BOT_NOTE_CHANGES,
                null,
                array(
                    array("note_id", $key),
                    array("deletion_date", null)
                ),
                array(
                    "DESC",
                    "id"
                ),
                $past
            );
            $size = sizeof($childQuery);

            if ($size === $past) {
                $query->changes = $childQuery[$size - 1];
                $query->settings = get_sql_query(
                    BotDatabaseTable::BOT_NOTE_SETTINGS,
                    null,
                    array(
                        array("note_id", $key),
                    ),
                    array(
                        "DESC",
                        "id"
                    ),
                    1
                )[0];
                $query->participants = get_sql_query(
                    BotDatabaseTable::BOT_NOTE_PARTICIPANTS,
                    null,
                    array(
                        array("note_id", $key),
                        array("ignore_date", null),
                    ),
                    null,
                    1
                )[0];

                if ($past != 1) {
                    if ($interaction->user->id == $query->user_id) {
                        $proceed = true;
                    } else if (!empty($query->participants)) {
                        $proceed = false;

                        foreach ($query->participants as $participant) {
                            if ($participant->user_id == $interaction->user->id) {
                                $proceed = $participant->read_history !== null;
                                break;
                            }
                        }
                    } else {
                        $proceed = false;
                    }
                } else {
                    $proceed = true;
                }

                if ($proceed) {
                    if ($query->settings->view_public
                        || $interaction->user->id == $query->user_id) {
                        return $query;
                    } else if (!empty($query->participants)) {
                        foreach ($query->participants as $participant) {
                            if ($participant->user_id == $interaction->user->id) {
                                return $query;
                            }
                        }
                    }
                }
            }
        }
        return null;
    }

    public function send(Interaction      $interaction,
                         int|float|string $key, int|string|null $userID = null): void
    {
        $object = $this->get($interaction, $key,
            $userID !== null ? $userID : $interaction->user->id);

        if ($object !== null) {
            $messageBuilder = MessageBuilder::new();
            $embed = new Embed($this->plan->discord);
            $embed->setAuthor($this->plan->utilities->getUsername($object->user_id));
            $embed->setTitle($object->changes->title);
            $embed->setDescription($object->changes->description);
            $embed->setTimestamp(strtotime($object->changes->creation_date));
            $messageBuilder->addEmbed($embed);

            if ($object->user_id == $interaction->user->id) {
                $manage = true;
            } else if (!empty($object->participants)) {
                $manage = false;

                foreach ($object->participants as $participant) {
                    if ($participant->user_id == $interaction->user->id) {
                        $manage = $participant->manage !== null;
                        break;
                    }
                }
            } else {
                $manage = false;
            }
            if ($manage && !empty($object->participants)) {
                $select = SelectMenu::new()
                    ->setMaxValues(1)
                    ->setMinValues(1)
                    ->setPlaceholder("Remove a Participant");

                foreach ($object->participants as $participant) {
                    $choice = Option::new($this->plan->utilities->getUsername($participant->user_id), $participant->id)
                        ->setDescription(
                            "Permissions: "
                            . ($participant->write !== null ? "Write" : "Read")
                            . ($participant->delete !== null ? " | Delete" : "")
                            . ($participant->manage !== null ? " | Manage" : "")
                        );
                    $select->addOption($choice);
                }
                $select->setListener(function (Interaction $interaction, Collection $options) use ($select, $object) {
                    $rowID = $options[0]->getValue();

                    if (set_sql_query(
                        BotDatabaseTable::BOT_NOTE_PARTICIPANTS,
                        array(
                            "ignore_date" => get_current_date(),
                            "deleted_by" => $interaction->user->id
                        ),
                        array(
                            array("id", $rowID),
                        ),
                        null,
                        1
                    )) {
                        $this->plan->utilities->acknowledgeMessage(
                            $interaction,
                            MessageBuilder::new()->setContent(
                                "Successfully deleted the note's participant."
                            ), true
                        );
                    } else {
                        global $logger;
                        $logger->logError(
                            $this->plan->planID,
                            "An database error occurred while deleting a note participant with ID: " . $rowID
                        );
                        $this->plan->utilities->acknowledgeMessage(
                            $interaction,
                            MessageBuilder::new()->setContent(
                                "An database error occurred while deleting the note participant."
                            ), true
                        );
                    }
                }, $this->plan->discord, true);
                $messageBuilder->addComponent($select);
            }
            $this->plan->utilities->acknowledgeMessage(
                $interaction,
                $messageBuilder,
                true
            );
        }
    }

    public function changeSetting(Interaction      $interaction,
                                  int|float|string $key, int|string|null $userID,
                                  ?bool            $viewPublic,
                                  ?bool            $viewHistory = null): void
    {
        $object = $this->get($interaction, $key,
            $userID !== null ? $userID : $interaction->user->id);

        if ($object !== null) {
            if ($object->user_id == $interaction->user->id) {
                $proceed = true;
            } else if (!empty($object->participants)) {
                $proceed = false;

                foreach ($object->participants as $participant) {
                    if ($participant->user_id == $interaction->user->id) {
                        $proceed = $participant->manage !== null;
                        break;
                    }
                }
            } else {
                $proceed = false;
            }

            if ($proceed) {
                if (sql_insert(
                    BotDatabaseTable::BOT_NOTE_SETTINGS,
                    array(
                        "note_id" => $key,
                        "user_id" => $interaction->user->id,
                        "view_public" => $viewPublic !== null ? $viewPublic : $object->settings->view_public,
                        "view_history" => $viewHistory !== null ? $viewHistory : $object->settings->view_history,
                        "creation_date" => get_current_date()
                    )
                )) {
                    $this->plan->utilities->acknowledgeMessage(
                        $interaction,
                        MessageBuilder::new()->setContent(
                            "Successfully changed the note's settings."
                        ), true
                    );
                } else {
                    global $logger;
                    $logger->logError(
                        $this->plan->planID,
                        "An database error occurred while changing the note settings for the note with ID: " . $object->id
                    );
                    $this->plan->utilities->acknowledgeMessage(
                        $interaction,
                        MessageBuilder::new()->setContent(
                            "An database error occurred while changing the note settings."
                        ), true
                    );
                }
            } else {
                $this->plan->utilities->acknowledgeMessage(
                    $interaction,
                    MessageBuilder::new()->setContent(
                        "You do not have permission to change this note's settings."
                    ), true
                );
            }
        } else {
            $this->plan->utilities->acknowledgeMessage(
                $interaction,
                MessageBuilder::new()->setContent(
                    self::NOT_EXISTS
                ), true
            );
        }
    }

    public function modifyParticipant(Interaction      $interaction,
                                      int|float|string $key, int|string|null $userID, int|string $participantID,
                                      ?bool            $readHistory,
                                      ?bool            $writePermission = null,
                                      ?bool            $deletePermission = null,
                                      ?bool            $managePermission = null): void
    {
        $object = $this->get($interaction, $key,
            $userID !== null ? $userID : $interaction->user->id);

        if ($object !== null) {
            if ($object->user_id == $interaction->user->id) {
                $proceed = true;
            } else if (!empty($object->participants)) {
                $proceed = false;

                foreach ($object->participants as $participant) {
                    if ($participant->user_id == $interaction->user->id) {
                        $proceed = $participant->manage !== null;
                        break;
                    }
                }
            } else {
                $proceed = false;
            }

            if ($proceed) {
                $foundParticipant = null;

                if (!empty($object->participants)) {
                    foreach ($object->participants as $participant) {
                        if ($participant->id == $participantID) {
                            $foundParticipant = $participant;
                            set_sql_query(
                                BotDatabaseTable::BOT_NOTE_PARTICIPANTS,
                                array(
                                    "ignore_date" => get_current_date(),
                                ),
                                array(
                                    array("id", $participant->id),
                                ),
                                null,
                                1
                            );
                            break;
                        }
                    }
                }
                if ($foundParticipant !== null) {
                    if (sql_insert(
                        BotDatabaseTable::BOT_NOTE_PARTICIPANTS,
                        array(
                            "note_id" => $key,
                            "user_id" => $interaction->user->id,
                            "participant_id" => $participantID,
                            "read_history" => $readHistory !== null ? $readHistory : $foundParticipant->read_history,
                            "write" => $writePermission !== null ? $writePermission : $foundParticipant->write,
                            "delete" => $deletePermission !== null ? $deletePermission : $foundParticipant->delete,
                            "manage" => $managePermission !== null ? $managePermission : $foundParticipant->manage,
                            "creation_date" => get_current_date()
                        )
                    )) {
                        $this->plan->utilities->acknowledgeMessage(
                            $interaction,
                            MessageBuilder::new()->setContent(
                                "Successfully modified the note's participant."
                            ), true
                        );
                    } else {
                        global $logger;
                        $logger->logError(
                            $this->plan->planID,
                            "An database error occurred while modifying a note participant for the note with ID: " . $object->id
                        );
                        $this->plan->utilities->acknowledgeMessage(
                            $interaction,
                            MessageBuilder::new()->setContent(
                                "An database error occurred while modifying the note participant."
                            ), true
                        );
                    }
                } else {
                    if (sql_insert(
                        BotDatabaseTable::BOT_NOTE_PARTICIPANTS,
                        array(
                            "note_id" => $key,
                            "user_id" => $interaction->user->id,
                            "participant_id" => $participantID,
                            "read_history" => $readHistory !== null ? $readHistory : true,
                            "write" => $writePermission !== null ? $writePermission : false,
                            "delete" => $deletePermission !== null ? $deletePermission : false,
                            "manage" => $managePermission !== null ? $managePermission : false,
                            "creation_date" => get_current_date()
                        )
                    )) {
                        $this->plan->utilities->acknowledgeMessage(
                            $interaction,
                            MessageBuilder::new()->setContent(
                                "Successfully modified the note's participant."
                            ), true
                        );
                    } else {
                        global $logger;
                        $logger->logError(
                            $this->plan->planID,
                            "An database error occurred while modifying a note participant for the note with ID: " . $object->id
                        );
                        $this->plan->utilities->acknowledgeMessage(
                            $interaction,
                            MessageBuilder::new()->setContent(
                                "An database error occurred while modifying the note participant."
                            ), true
                        );
                    }
                }
            } else {
                $this->plan->utilities->acknowledgeMessage(
                    $interaction,
                    MessageBuilder::new()->setContent(
                        "You do not have permission to modify this note's participant."
                    ), true
                );
            }
        } else {
            $this->plan->utilities->acknowledgeMessage(
                $interaction,
                MessageBuilder::new()->setContent(
                    self::NOT_EXISTS
                ), true
            );
        }
    }
}