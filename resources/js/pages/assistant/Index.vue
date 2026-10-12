<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type AccountRow = { id: number; name: string; balance: number };
type CategorySpend = { name: string; emoji: string; amount: number };
type LimitRow = {
    category: string;
    amount: number;
    spent: number;
    remaining: number;
    exceeded: boolean;
};
type GoalRow = {
    name: string;
    progress: number;
    target: number;
    date: string | null;
};
type DebtRow = { direction: string; name: string; remainder: number };
type RecurrenceRow = {
    description: string | null;
    type: string;
    amount: number;
    next_date: string;
};
type ExpenseCategory = { id: number; name: string; emoji: string };

type Snapshot = {
    month: string;
    month_label: string;
    currency: string;
    workspace_name: string;
    total_visible: number;
    accounts: AccountRow[];
    income: number;
    expense: number;
    previous_expense: number;
    forecast: number | null;
    top_expense_categories: CategorySpend[];
    limits: LimitRow[];
    goals: GoalRow[];
    debts: DebtRow[];
    recurrences: RecurrenceRow[];
    expense_categories: ExpenseCategory[];
};

type DraftLine = { label: string; value: string };
type DraftSubmit = {
    action: string;
    fields: Record<string, string | number>;
};
type Draft = {
    type: string;
    amount?: number;
    account?: string;
    account_id?: number | null;
    category?: string;
    category_id?: number | null;
    date?: string;
    description?: string;
    lines?: DraftLine[];
    submit?: DraftSubmit | null;
    blocked?: string | null;
    recorded?: boolean;
};

type ChatMessage = {
    id?: number;
    role: 'user' | 'assistant';
    text: string;
    draft?: Draft;
};

type Conversation = {
    id: number;
    title: string;
    last_message_at: string | null;
    messages_count: number;
};

const { snapshot, model, provider, configured, conversations, activeConversationId, activeMessages } = defineProps<{
    snapshot: Snapshot;
    model: string;
    provider: string;
    configured: boolean;
    conversations: Conversation[];
    activeConversationId: number | null;
    activeMessages: ChatMessage[];
}>();

const page = usePage();
const currency = computed(() => {
    const workspace = page.props.workspace as { currency?: string } | null;

    return workspace?.currency ?? snapshot.currency;
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Помощник',
                href: '/assistant',
            },
        ],
    },
});

const introMessage: ChatMessage = {
    role: 'assistant',
    text: `Смотрю только бюджет «${snapshot.workspace_name}» за ${snapshot.month_label}. Цифры считает сервер. Операция, категория, счёт, цель, долг, лимит или регулярный платёж попадут в бюджет только после кнопки «Записать».`,
};
const messages = ref<ChatMessage[]>(
    activeMessages.length > 0 ? [...activeMessages] : [introMessage],
);
const currentConversationId = ref<number | null>(activeConversationId);
const conversationList = ref<Conversation[]>([...conversations]);
const input = ref('');
const sending = ref(false);
const error = ref('');
const loadingConversation = ref(false);
const recordingIndex = ref<number | null>(null);
const recordError = ref('');
const recordErrorIndex = ref<number | null>(null);
const messagesEl = ref<HTMLElement | null>(null);
const chatEnd = ref<HTMLElement | null>(null);
const prompts = [
    'Куда ушли деньги?',
    'кофе 350 с карты',
    'цель отпуск 150000',
    'лимит на кафе 5000',
];

function formatAmount(minor: number): string {
    const negative = minor < 0;
    const abs = Math.abs(minor);
    const whole = Math.floor(abs / 100);
    const cents = String(abs % 100).padStart(2, '0');

    return `${negative ? '−' : ''}${whole}.${cents} ${currency.value}`;
}

function toDecimal(minor: number): string {
    const negative = minor < 0;
    const abs = Math.abs(minor);
    const whole = Math.floor(abs / 100);
    const cents = String(abs % 100).padStart(2, '0');

    return `${negative ? '-' : ''}${whole}.${cents}`;
}

function debtLabel(direction: string): string {
    if (direction === 'they_owe') {
        return 'мне должны';
    }

    if (direction === 'i_owe') {
        return 'я должен';
    }

    return direction;
}

function xsrfToken(): string {
    const row = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return row ? decodeURIComponent(row.slice('XSRF-TOKEN='.length)) : '';
}

function historyPayload(): { role: 'user' | 'assistant'; content: string }[] {
    return messages.value.map((message) => ({
        role: message.role,
        content: message.text,
    }));
}

async function ask(text?: string): Promise<void> {
    const message = (text ?? input.value).trim();

    if (message === '' || sending.value || !configured) {
        return;
    }

    sending.value = true;
    error.value = '';
    messages.value.push({ role: 'user', text: message });
    input.value = '';

    try {
        const response = await fetch('/assistant', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                message,
                conversation_id: currentConversationId.value,
                history: historyPayload().slice(0, -1),
            }),
        });

        const data = (await response.json()) as {
            text?: string;
            draft?: Draft | null;
            conversation_id?: number;
            message_id?: number;
            message?: string;
            errors?: { message?: string[] };
        };

        if (!response.ok) {
            const fromErrors = data.errors?.message?.[0];
            throw new Error(
                fromErrors ?? data.message ?? 'Модель не ответила.',
            );
        }

        messages.value.push({
            id: data.message_id,
            role: 'assistant',
            text: data.text ?? '',
            draft: data.draft ?? undefined,
        });
        if (data.conversation_id) {
            currentConversationId.value = data.conversation_id;
            const existing = conversationList.value.find(
                (conversation) => conversation.id === data.conversation_id,
            );
            if (existing) {
                existing.messages_count += 2;
                existing.last_message_at = new Date().toISOString();
            } else {
                conversationList.value.unshift({
                    id: data.conversation_id,
                    title: message.slice(0, 120),
                    last_message_at: new Date().toISOString(),
                    messages_count: 2,
                });
            }
        }
    } catch (caught) {
        error.value =
            caught instanceof Error ? caught.message : 'Модель не ответила.';
        messages.value.push({
            role: 'assistant',
            text: error.value,
        });
    } finally {
        sending.value = false;
    }
}

function startConversation(): void {
    currentConversationId.value = null;
    messages.value = [introMessage];
    input.value = '';
    error.value = '';
}

async function loadConversation(id: number): Promise<void> {
    if (id === currentConversationId.value || loadingConversation.value) {
        return;
    }

    loadingConversation.value = true;
    error.value = '';

    try {
        const response = await fetch(`/assistant/conversations/${id}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) {
            throw new Error('Не удалось открыть историю.');
        }
        const data = (await response.json()) as {
            conversation_id: number;
            messages: ChatMessage[];
        };
        currentConversationId.value = data.conversation_id;
        messages.value = data.messages.length > 0 ? data.messages : [introMessage];
        scrollChatToEnd();
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : 'Не удалось открыть историю.';
    } finally {
        loadingConversation.value = false;
    }
}

function formatConversationDate(value: string | null): string {
    if (!value) return '';
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short' }).format(new Date(value));
}

function scrollChatToEnd(): void {
    nextTick(() => {
        requestAnimationFrame(() => {
            if (messagesEl.value) {
                messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
            }

            chatEnd.value?.scrollIntoView({ block: 'end' });
        });
    });
}

onMounted(scrollChatToEnd);

watch(() => messages.value.length, scrollChatToEnd);

function discardDraft(index: number): void {
    const message = messages.value[index];

    if (message === undefined || message.draft?.recorded || recordingIndex.value === index) {
        return;
    }

    messages.value[index] = { role: message.role, text: message.text, id: message.id };
}

function draftAction(draft: Draft): DraftSubmit | null {
    if (draft.recorded) {
        return null;
    }

    if (draft.submit) {
        return draft.submit;
    }

    if (draft.type === 'Расход' && draft.account_id && draft.category_id) {
        return {
            action: '/transactions/expense',
            fields: {
                account_id: draft.account_id,
                category_id: draft.category_id,
                amount: toDecimal(draft.amount ?? 0),
                occurred_on: draft.date ?? '',
                description: draft.description ?? '',
            },
        };
    }

    return null;
}

async function recordDraft(index: number): Promise<void> {
    const message = messages.value[index];
    const submit = message?.draft ? draftAction(message.draft) : null;

    if (!message?.draft || !submit || recordingIndex.value !== null) {
        return;
    }

    recordingIndex.value = index;
    recordError.value = '';
    recordErrorIndex.value = null;

    try {
        const body = new FormData();

        for (const [name, value] of Object.entries(submit.fields)) {
            body.append(name, String(value));
        }

        const response = await fetch(submit.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body,
        });

        if (!response.ok) {
            const data = (await response.json().catch(() => null)) as {
                message?: string;
                errors?: Record<string, string[]>;
            } | null;
            const firstError = data?.errors
                ? Object.values(data.errors)[0]?.[0]
                : undefined;

            throw new Error(firstError ?? data?.message ?? 'Не удалось записать.');
        }

        messages.value[index] = {
            ...message,
            draft: { ...message.draft, recorded: true },
        };

        if (message.id) {
            const saved = await fetch(`/assistant/messages/${message.id}/recorded`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            });

            if (!saved.ok) {
                throw new Error('Запись прошла, но черновик не удалось закрыть. Обновите страницу и не нажимайте «Записать» ещё раз.');
            }
        }
    } catch (caught) {
        recordError.value = caught instanceof Error ? caught.message : 'Не удалось записать.';
        recordErrorIndex.value = index;
    } finally {
        recordingIndex.value = null;
    }
}
</script>

<template>
    <Head title="Помощник" />

    <div
        class="mx-auto grid w-full max-w-6xl gap-6 p-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start"
    >
        <section
            class="border-border bg-background flex h-[calc(100svh-8.5rem)] min-h-[28rem] flex-col overflow-hidden rounded-2xl border"
        >
            <header
                class="border-border flex items-start justify-between gap-4 border-b px-5 py-4"
            >
                <div class="space-y-1">
                    <h1 class="font-display text-2xl tracking-tight">
                        Помощник
                    </h1>
                    <p class="text-muted-foreground text-sm">
                        Три задачи: объяснить цифры месяца, подсказать категорию
                        на чеке, собрать черновик из фразы.
                    </p>
                </div>
                <span
                    class="bg-secondary text-secondary-foreground shrink-0 rounded-full px-2.5 py-1 font-mono text-[11px]"
                >
                    {{ provider }} · {{ model }}
                </span>
            </header>

            <div
                ref="messagesEl"
                class="assistant-messages flex flex-1 flex-col gap-4 overflow-y-auto px-5 py-5"
            >
                <article
                    v-for="(message, index) in messages"
                    :key="index"
                    class="flex"
                    :class="
                        message.role === 'user'
                            ? 'justify-end'
                            : 'justify-start'
                    "
                >
                    <div
                        class="max-w-[36rem] rounded-2xl px-4 py-3 text-sm leading-relaxed whitespace-pre-line"
                        :class="
                            message.role === 'user'
                                ? 'bg-primary text-primary-foreground rounded-br-md'
                                : 'bg-secondary text-foreground rounded-bl-md'
                        "
                    >
                        <p>{{ message.text }}</p>
                        <div
                            v-if="message.draft"
                            class="border-border/60 bg-background text-foreground mt-3 rounded-xl border px-3 py-3"
                        >
                            <p
                                class="text-muted-foreground mb-2 text-[11px] tracking-wide uppercase"
                            >
                                Черновик
                            </p>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5">
                                <template v-if="message.draft.lines?.length">
                                    <template
                                        v-for="(line, lineIndex) in message.draft
                                            .lines"
                                        :key="`${index}-${lineIndex}`"
                                    >
                                        <dt class="text-muted-foreground">
                                            {{ line.label }}
                                        </dt>
                                        <dd>{{ line.value }}</dd>
                                    </template>
                                </template>
                                <template v-else>
                                    <dt class="text-muted-foreground">Тип</dt>
                                    <dd>{{ message.draft.type }}</dd>
                                    <dt class="text-muted-foreground">Сумма</dt>
                                    <dd class="font-medium">
                                        {{
                                            formatAmount(message.draft.amount ?? 0)
                                        }}
                                    </dd>
                                    <dt class="text-muted-foreground">Счёт</dt>
                                    <dd>{{ message.draft.account }}</dd>
                                    <dt class="text-muted-foreground">
                                        Категория
                                    </dt>
                                    <dd>{{ message.draft.category }}</dd>
                                    <dt class="text-muted-foreground">Дата</dt>
                                    <dd>{{ message.draft.date }}</dd>
                                    <dt class="text-muted-foreground">
                                        Описание
                                    </dt>
                                    <dd>{{ message.draft.description }}</dd>
                                </template>
                            </dl>
                            <p
                                v-if="message.draft.blocked"
                                class="text-muted-foreground mt-2 text-xs"
                            >
                                {{ message.draft.blocked }}
                            </p>
                            <p
                                v-if="message.draft.recorded"
                                class="mt-3 text-xs text-emerald-600 dark:text-emerald-400"
                            >
                                Записано
                            </p>
                            <div v-else class="mt-3 flex gap-2">
                                <Button
                                    v-if="
                                        draftAction(message.draft) &&
                                        !message.draft.blocked
                                    "
                                    size="sm"
                                    type="button"
                                    :disabled="recordingIndex !== null"
                                    @click="recordDraft(index)"
                                >
                                    <Spinner v-if="recordingIndex === index" />
                                    Записать
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    type="button"
                                    :disabled="recordingIndex === index"
                                    @click="discardDraft(index)"
                                >
                                    Отмена
                                </Button>
                            </div>
                            <p
                                v-if="recordErrorIndex === index"
                                class="text-destructive mt-2 text-xs"
                            >
                                {{ recordError }}
                            </p>
                        </div>
                    </div>
                </article>
            </div>

            <footer
                ref="chatEnd"
                class="border-border space-y-3 border-t px-5 py-4"
            >
                <p class="text-muted-foreground text-xs">
                    {{
                        configured
                            ? 'Модель видит снимок справа. В бюджет попадает только кнопка «Записать».'
                            : 'Нет ключа AI-провайдера. Журнал и чек работают, чат выключен.'
                    }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="prompt in prompts"
                        :key="prompt"
                        type="button"
                        class="border-border hover:bg-secondary rounded-full border px-3 py-1 text-xs"
                        :disabled="!configured || sending"
                        @click="ask(prompt)"
                    >
                        {{ prompt }}
                    </button>
                </div>
                <form class="flex gap-2" @submit.prevent="ask()">
                    <input
                        v-model="input"
                        type="text"
                        :disabled="!configured || sending"
                        maxlength="1000"
                        placeholder="Куда ушли деньги? / кофе 350 / цель отпуск 150000"
                        class="border-input bg-background ring-offset-background placeholder:text-muted-foreground h-10 flex-1 rounded-md border px-3 text-sm"
                    />
                    <Button type="submit" :disabled="!configured || sending">
                        <Spinner v-if="sending" />
                        Спросить
                    </Button>
                </form>
            </footer>
        </section>

        <aside
            class="border-border bg-card text-card-foreground rounded-2xl border px-5 py-5"
        >
            <div class="mb-5 border-b border-dashed pb-5">
                <div class="flex items-center justify-between gap-3">
                    <p
                        class="text-muted-foreground font-mono text-[11px] tracking-[0.18em] uppercase"
                    >
                        История чатов
                    </p>
                    <button
                        type="button"
                        class="text-primary text-xs font-medium hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @click="startConversation"
                    >
                        Новый чат
                    </button>
                </div>
                <p v-if="conversationList.length === 0" class="text-muted-foreground mt-3 text-sm">
                    Здесь появятся ваши разговоры с помощником.
                </p>
                <div v-else class="mt-3 max-h-56 space-y-1 overflow-y-auto">
                    <button
                        v-for="conversation in conversationList"
                        :key="conversation.id"
                        type="button"
                        class="hover:bg-secondary flex w-full items-center justify-between gap-3 rounded-lg px-2.5 py-2 text-left text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="conversation.id === currentConversationId ? 'bg-secondary' : ''"
                        @click="loadConversation(conversation.id)"
                    >
                        <span class="min-w-0 truncate">{{ conversation.title }}</span>
                        <span class="text-muted-foreground shrink-0 text-[11px]">
                            {{ formatConversationDate(conversation.last_message_at) }}
                        </span>
                    </button>
                </div>
            </div>
            <p
                class="text-muted-foreground font-mono text-[11px] tracking-[0.18em] uppercase"
            >
                Снимок в модель
            </p>
            <h2 class="font-display mt-1 text-lg">
                {{ snapshot.workspace_name }}
            </h2>
            <p class="text-muted-foreground text-sm">
                {{ snapshot.month_label }} · только этот бюджет
            </p>

            <dl class="mt-5 space-y-3 border-t border-dashed pt-4 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Итог счетов</dt>
                    <dd class="font-medium">
                        {{ formatAmount(snapshot.total_visible) }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Доход месяца</dt>
                    <dd>{{ formatAmount(snapshot.income) }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Расход месяца</dt>
                    <dd>{{ formatAmount(snapshot.expense) }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Прогноз месяца</dt>
                    <dd>
                        {{
                            snapshot.forecast === null
                                ? 'нет'
                                : formatAmount(snapshot.forecast)
                        }}
                    </dd>
                </div>
            </dl>

            <div class="mt-5 space-y-4 border-t border-dashed pt-4 text-sm">
                <div>
                    <p class="text-muted-foreground mb-1.5 text-xs">Счета</p>
                    <p v-if="snapshot.accounts.length === 0">нет</p>
                    <ul v-else class="space-y-1">
                        <li
                            v-for="account in snapshot.accounts"
                            :key="account.id"
                            class="flex justify-between gap-3"
                        >
                            <span>{{ account.name }}</span>
                            <span>{{ formatAmount(account.balance) }}</span>
                        </li>
                    </ul>
                </div>

                <div>
                    <p class="text-muted-foreground mb-1.5 text-xs">
                        Топ расходов
                    </p>
                    <p v-if="snapshot.top_expense_categories.length === 0">
                        нет
                    </p>
                    <ul v-else class="space-y-1">
                        <li
                            v-for="row in snapshot.top_expense_categories"
                            :key="row.name"
                            class="flex justify-between gap-3"
                        >
                            <span>{{ row.emoji }} {{ row.name }}</span>
                            <span>{{ formatAmount(row.amount) }}</span>
                        </li>
                    </ul>
                </div>

                <div>
                    <p class="text-muted-foreground mb-1.5 text-xs">Лимиты</p>
                    <p v-if="snapshot.limits.length === 0">нет</p>
                    <ul v-else class="space-y-1">
                        <li
                            v-for="limit in snapshot.limits"
                            :key="limit.category"
                        >
                            {{ limit.category }}:
                            {{ formatAmount(limit.spent) }} из
                            {{ formatAmount(limit.amount) }}
                        </li>
                    </ul>
                </div>

                <div>
                    <p class="text-muted-foreground mb-1.5 text-xs">Цели</p>
                    <p v-if="snapshot.goals.length === 0">нет</p>
                    <ul v-else class="space-y-1">
                        <li v-for="goal in snapshot.goals" :key="goal.name">
                            {{ goal.name }}: {{ formatAmount(goal.progress) }} /
                            {{ formatAmount(goal.target) }}
                        </li>
                    </ul>
                </div>

                <div>
                    <p class="text-muted-foreground mb-1.5 text-xs">Долги</p>
                    <p v-if="snapshot.debts.length === 0">нет</p>
                    <ul v-else class="space-y-1">
                        <li
                            v-for="debt in snapshot.debts"
                            :key="debt.name"
                            class="flex justify-between gap-3"
                        >
                            <span
                                >{{ debtLabel(debt.direction) }} ·
                                {{ debt.name }}</span
                            >
                            <span>{{ formatAmount(debt.remainder) }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div
                class="text-muted-foreground mt-5 space-y-1 border-t border-dashed pt-4 text-xs"
            >
                <p>В снимок не входит:</p>
                <p>журнал построчно, пароли, ИНН ФНС, email, чужие бюджеты.</p>
            </div>
        </aside>
    </div>
</template>
