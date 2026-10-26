<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type ExpensePoint = {
    date: string;
    amount: number;
};

type LimitRow = {
    id: number;
    category_id: number;
    category_name: string;
    category_emoji: string;
    category_color: string;
    amount: number;
    spent: number;
    exceeded: boolean;
};

type GoalRow = {
    id: number;
    name: string;
    target_amount: number;
    target_date: string | null;
    notes: string | null;
    progress: number;
};

type DebtRow = {
    id: number;
    direction: 'they_owe' | 'i_owe' | string;
    counterparty_name: string;
    original_amount: number;
    notes: string | null;
    remainder: number;
};

type RecurrenceRow = {
    id: number;
    type: 'income' | 'expense' | 'transfer' | string;
    amount: number;
    description: string | null;
    next_occurred_on: string;
};

type RecommendationRow = {
    kind: string;
    label: string;
    body: string;
    date: string;
};

const {
    total,
    hasAccounts,
    expenseLine,
    limits,
    goals,
    debts,
    upcomingRecurrences,
    recommendations,
} = defineProps<{
    total: number;
    hasAccounts: boolean;
    expenseLine: ExpensePoint[];
    limits: LimitRow[];
    goals: GoalRow[];
    debts: DebtRow[];
    upcomingRecurrences: RecurrenceRow[];
    recommendations: RecommendationRow[];
}>();

const recommendationOpen = ref(false);
const selectedRecommendationDate = ref<string | null>(null);

const latestRecommendation = computed(
    () => recommendations.at(-1) ?? null,
);

const selectedRecommendation = computed(() => {
    return (
        recommendations.find(
            (item) => item.date === selectedRecommendationDate.value,
        ) ?? latestRecommendation.value
    );
});

function toggleRecommendation(): void {
    recommendationOpen.value = !recommendationOpen.value;

    if (recommendationOpen.value) {
        selectedRecommendationDate.value = latestRecommendation.value?.date ?? null;
    }
}

function recommendationDay(date: string): string {
    return new Intl.DateTimeFormat('ru-RU', {
        day: 'numeric',
        month: 'short',
    }).format(new Date(`${date}T00:00:00`));
}

const page = usePage();
const currency = computed(() => {
    const workspace = page.props.workspace as { currency?: string } | null;

    return workspace?.currency ?? '';
});

const monthLabel = computed(() => {
    const names = [
        'января',
        'февраля',
        'марта',
        'апреля',
        'мая',
        'июня',
        'июля',
        'августа',
        'сентября',
        'октября',
        'ноября',
        'декабря',
    ];
    const sample = expenseLine[0]?.date;
    const month = sample
        ? Number(sample.slice(5, 7))
        : new Date().getMonth() + 1;

    return names[month - 1] ?? '';
});

const linePoints = computed(() => {
    if (expenseLine.length === 0) {
        return '';
    }

    const peak = Math.max(...expenseLine.map((point) => point.amount), 1);
    const last = expenseLine.length - 1;

    return expenseLine
        .map((point, index) => {
            const x = last === 0 ? 0 : (index / last) * 100;
            const y = 36 - (point.amount / peak) * 32;

            return `${x.toFixed(2)},${y.toFixed(2)}`;
        })
        .join(' ');
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Обзор',
                href: '/overview',
            },
        ],
    },
});

function formatAmount(minor: number): string {
    const negative = minor < 0;
    const abs = Math.abs(minor);
    const whole = Math.floor(abs / 100);
    const cents = String(abs % 100).padStart(2, '0');

    return `${negative ? '−' : ''}${whole}.${cents} ${currency.value}`;
}

function spentPercent(limit: LimitRow): number {
    if (limit.amount <= 0) {
        return 0;
    }

    return Math.min(100, Math.round((limit.spent / limit.amount) * 100));
}

function progressPercent(goal: GoalRow): number {
    if (goal.target_amount <= 0) {
        return 0;
    }

    return Math.min(
        100,
        Math.round((goal.progress / goal.target_amount) * 100),
    );
}

function directionLabel(direction: DebtRow['direction']): string {
    if (direction === 'they_owe') {
        return 'Мне должны';
    }

    if (direction === 'i_owe') {
        return 'Я должен';
    }

    return direction;
}

function typeLabel(type: RecurrenceRow['type']): string {
    if (type === 'income') {
        return 'Доход';
    }

    if (type === 'transfer') {
        return 'Перевод';
    }

    return 'Расход';
}
</script>

<template>
    <Head title="Обзор" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-10 p-4">
        <section class="flex flex-col gap-6">
            <div v-if="!hasAccounts" class="flex flex-col gap-3">
                <h1 class="font-display text-4xl tracking-tight sm:text-5xl">
                    Здесь будет итог
                </h1>
                <p class="text-muted-foreground max-w-md text-sm">
                    Пока нет счетов — нечего складывать. Создайте первый, и на
                    этом месте появится сумма бюджета.
                </p>
                <Link
                    href="/accounts"
                    class="text-brand w-fit text-sm font-medium underline-offset-4 hover:underline"
                >
                    Создайте первый счёт
                </Link>
            </div>

            <div v-else class="flex flex-col gap-2">
                <p class="text-muted-foreground text-sm">Все счета</p>
                <p
                    class="font-display text-6xl leading-none tracking-tight tabular-nums sm:text-7xl md:text-8xl"
                >
                    {{ formatAmount(total) }}
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <p class="text-muted-foreground text-sm">
                    Расходы {{ monthLabel }}
                </p>
                <svg
                    class="text-expense h-24 w-full"
                    viewBox="0 0 100 40"
                    preserveAspectRatio="none"
                    role="img"
                    :aria-label="`Расходы ${monthLabel}`"
                >
                    <polyline
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.25"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        vector-effect="non-scaling-stroke"
                        :points="linePoints"
                    />
                </svg>
            </div>
        </section>

        <section
            v-if="latestRecommendation"
            class="border-border rounded-xl border"
        >
            <button
                type="button"
                class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left"
                :aria-expanded="recommendationOpen"
                @click="toggleRecommendation"
            >
                <span class="text-sm">
                    <span class="text-muted-foreground">Рекомендация</span>
                    <span aria-hidden="true"> · </span>
                    {{ latestRecommendation.label }}
                    <span class="text-muted-foreground">
                        · {{ recommendationDay(latestRecommendation.date) }}
                    </span>
                </span>
                <span class="text-muted-foreground shrink-0 text-xs">
                    {{ recommendationOpen ? 'Скрыть' : 'Открыть' }}
                </span>
            </button>
            <div
                v-if="recommendationOpen && selectedRecommendation"
                class="border-border space-y-3 border-t px-3 py-3"
            >
                <p class="text-sm leading-relaxed whitespace-pre-wrap">
                    {{ selectedRecommendation.body }}
                </p>
                <div
                    v-if="recommendations.length > 1"
                    class="flex flex-wrap gap-1"
                >
                    <button
                        v-for="item in recommendations"
                        :key="item.date"
                        type="button"
                        class="rounded-full border px-2 py-0.5 text-xs"
                        :class="
                            item.date === selectedRecommendation.date
                                ? 'border-foreground'
                                : 'border-border text-muted-foreground'
                        "
                        @click="selectedRecommendationDate = item.date"
                    >
                        {{ item.label }} · {{ recommendationDay(item.date) }}
                    </button>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
            <section class="flex flex-col gap-3">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="font-medium">Лимиты</h2>
                    <Link
                        href="/limits"
                        class="text-muted-foreground text-xs hover:underline"
                    >
                        Все
                    </Link>
                </div>
                <p
                    v-if="limits.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    Лимитов нет.
                </p>
                <ul class="flex flex-col gap-3">
                    <li
                        v-for="limit in limits"
                        :key="limit.id"
                        class="flex flex-col gap-2"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="min-w-0 truncate text-sm">
                                <span>{{ limit.category_emoji }}</span>
                                {{ limit.category_name }}
                            </p>
                            <p
                                class="shrink-0 text-sm tabular-nums"
                                :class="
                                    limit.exceeded
                                        ? 'text-warning'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ formatAmount(limit.spent) }} /
                                {{ formatAmount(limit.amount) }}
                            </p>
                        </div>
                        <div class="bg-muted h-px overflow-hidden">
                            <div
                                class="h-full"
                                :class="
                                    limit.exceeded ? 'bg-warning' : 'bg-expense'
                                "
                                :style="{
                                    width: `${spentPercent(limit)}%`,
                                }"
                            />
                        </div>
                    </li>
                </ul>
            </section>

            <section class="flex flex-col gap-3">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="font-medium">Цели</h2>
                    <Link
                        href="/goals"
                        class="text-muted-foreground text-xs hover:underline"
                    >
                        Все
                    </Link>
                </div>
                <p
                    v-if="goals.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    Целей нет.
                </p>
                <ul class="flex flex-col gap-3">
                    <li
                        v-for="goal in goals"
                        :key="goal.id"
                        class="flex flex-col gap-2"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="min-w-0 truncate text-sm">
                                {{ goal.name }}
                            </p>
                            <p
                                class="text-muted-foreground shrink-0 text-sm tabular-nums"
                            >
                                {{ formatAmount(goal.progress) }} /
                                {{ formatAmount(goal.target_amount) }}
                            </p>
                        </div>
                        <div class="bg-muted h-px overflow-hidden">
                            <div
                                class="bg-brand h-full"
                                :style="{
                                    width: `${progressPercent(goal)}%`,
                                }"
                            />
                        </div>
                    </li>
                </ul>
            </section>

            <section class="flex flex-col gap-3">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="font-medium">Долги</h2>
                    <Link
                        href="/debts"
                        class="text-muted-foreground text-xs hover:underline"
                    >
                        Все
                    </Link>
                </div>
                <p
                    v-if="debts.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    Долгов нет.
                </p>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="debt in debts"
                        :key="debt.id"
                        class="flex items-baseline justify-between gap-3"
                    >
                        <p class="min-w-0 truncate text-sm">
                            {{ debt.counterparty_name }}
                            <span class="text-muted-foreground">
                                · {{ directionLabel(debt.direction) }}
                            </span>
                        </p>
                        <p class="shrink-0 text-sm tabular-nums">
                            {{ formatAmount(debt.remainder) }}
                        </p>
                    </li>
                </ul>
            </section>

            <section class="flex flex-col gap-3">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="font-medium">Ближайшие шаблоны</h2>
                    <Link
                        href="/recurrences"
                        class="text-muted-foreground text-xs hover:underline"
                    >
                        Все
                    </Link>
                </div>
                <p
                    v-if="upcomingRecurrences.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    Активных шаблонов нет.
                </p>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="recurrence in upcomingRecurrences"
                        :key="recurrence.id"
                        class="flex items-baseline justify-between gap-3"
                    >
                        <p class="min-w-0 truncate text-sm">
                            {{
                                recurrence.description ||
                                typeLabel(recurrence.type)
                            }}
                            <span class="text-muted-foreground">
                                · {{ recurrence.next_occurred_on }}
                            </span>
                        </p>
                        <p class="shrink-0 text-sm tabular-nums">
                            {{ formatAmount(recurrence.amount) }}
                        </p>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
