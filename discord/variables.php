<?php

class BotDatabaseTable
{
    public const
        BOT_PLANS = "discord.botPlans",
        BOT_CHANNELS = "discord.botChannels",
        BOT_LOGS = "discord.botLogs",
        BOT_TARGETS = "discord.botTargets", //todo
        BOT_TARGET_EXECUTIONS = "discord.botTargetExecutions",
        BOT_MODAL_COMPONENTS = "discord.botModalComponents",
        BOT_MODAL_SUB_COMPONENTS = "discord.botModalSubComponents",
        BOT_BUTTON_COMPONENTS = "discord.botButtonComponents",
        BOT_SELECTION_COMPONENTS = "discord.botSelectionComponents",
        BOT_SELECTION_SUB_COMPONENTS = "discord.botSelectionSubComponents",
        BOT_CONTROLLED_MESSAGES = "discord.botControlledMessages",
        BOT_ERRORS = "discord.botErrors",
        BOT_MENTIONS = "discord.botMentions",
        BOT_LOCAL_INSTRUCTIONS = "discord.botLocalInstructions",
        BOT_PUBLIC_INSTRUCTIONS = "discord.botPublicInstructions",
        BOT_INSTRUCTION_PLACEHOLDERS = "discord.botInstructionPlaceholders",
        BOT_MESSAGES = "discord.botMessages",
        BOT_REPLIES = "discord.botReplies",
        BOT_PUNISHMENTS = "discord.botPunishments",
        BOT_PUNISHMENT_TYPES = "discord.botPunishmentTypes",
        BOT_WHITELIST = "discord.botWhitelist",
        BOT_COST_LIMITS = "discord.botMessageLimits",
        BOT_MESSAGE_LIMITS = "discord.botMessageLimits",
        BOT_KEYWORDS = "discord.botKeywords",
        BOT_CHAT_MODEL = "discord.botChatModel",
        CURRENCIES = "discord.currencies",
        BOT_COMMANDS = "discord.botCommands";
}

class DiscordPunishment
{
    public const
        DISCORD_BAN = 1,
        DISCORD_KICK = 2,
        DISCORD_TIMEOUT = 3,
        CUSTOM_BLACKLIST = 4;
}

class DiscordSyntax
{
    public const
        ITALICS = "*",
        UNDERLINE_ITALICS = array("__*", "*__"),
        BOLD = "**",
        UNDERLINE_BOLD = array("__**", "**__"),
        BOLD_ITALICS = "***",
        UNDERLINE_BOLD_ITALICS = array("__***", "***__"),
        UNDERLINE = "__",
        STRIKETHROUGH = "~~",
        BIG_HEADER = "#",
        MEDIUM_HEADER = "##",
        SMALL_HEADER = "###",
        LIST = "-",
        CODE_BLOCK = "`",
        HEAVY_CODE_BLOCK = "```",
        QUOTE = ">",
        MULTI_QUOTE = ">>>",
        SPOILER = "||";

    public static function htmlToDiscord(string $string): string
    {
        return strip_tags(
            str_replace("<h1>", DiscordSyntax::BIG_HEADER,
                str_replace("</h1>", "\n",
                    str_replace("<h2>", DiscordSyntax::MEDIUM_HEADER,
                        str_replace("</h2>", "\n",
                            str_replace("<h3>", DiscordSyntax::SMALL_HEADER,
                                str_replace("</h3>", "\n",
                                    str_replace("<br>", "\n",
                                        str_replace("<u>", DiscordSyntax::UNDERLINE,
                                            str_replace("</u>", DiscordSyntax::UNDERLINE,
                                                str_replace("<i>", DiscordSyntax::ITALICS,
                                                    str_replace("</i>", DiscordSyntax::ITALICS,
                                                        str_replace("<b>", DiscordSyntax::BOLD,
                                                            str_replace("</b>", DiscordSyntax::BOLD, $string)
                                                        )
                                                    )
                                                )
                                            )
                                        )
                                    )
                                )
                            )
                        )
                    )
                )
            )
        );
    }
}

class DiscordProperties
{
    private const STRICT_REPLY_INSTRUCTIONS_DEFAULT = "MOST IMPORTANT: "
    . "IF YOU ARE NOT OVER 90% CERTAIN THE USER'S MESSAGE IS RELATED TO THE FOLLOWING INFORMATION";

    public const
        MAX_BUTTONS_PER_ACTION_ROW = 5,
        MESSAGE_MAX_LENGTH = 2000,
        MESSAGE_NITRO_MAX_LENGTH = 4000,
        SYSTEM_REFRESH_MILLISECONDS = 300_000, // 5 minutes
        NEW_LINE = "\n",
        DEFAULT_PLACEHOLDER_START = "%%__",
        DEFAULT_PLACEHOLDER_MIDDLE = "__",
        DEFAULT_PLACEHOLDER_END = "__%%",
        NO_REPLY = self::DEFAULT_PLACEHOLDER_START . "empty" . self::DEFAULT_PLACEHOLDER_END,
        STRICT_REPLY_INSTRUCTIONS = self::STRICT_REPLY_INSTRUCTIONS_DEFAULT . ", JUST REPLY WITH: " . self::NO_REPLY,
        STRICT_REPLY_INSTRUCTIONS_WITH_MENTION = self::STRICT_REPLY_INSTRUCTIONS_DEFAULT . ", KINDLY NOTIFY THE USER.";
}
