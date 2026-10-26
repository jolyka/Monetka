<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CheckCircle2, ExternalLink, KeyRound, ServerCog } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';

type Provider = {
    label: string;
    description: string;
    base_url: string;
    model: string;
    requires_key: boolean;
};

type Settings = {
    provider: string;
    api_key_configured: boolean;
    api_key_hint: string | null;
    model: string;
    base_url: string;
    system_prompt: string;
    default_system_prompt: string;
};

const props = defineProps<{
    settings: Settings;
    providers: Record<string, Provider>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'AI-провайдеры',
                href: '/admin/ai',
            },
        ],
    },
});

const form = useForm({
    provider: props.settings.provider,
    api_key: '',
    clear_api_key: false,
    model: props.settings.model,
    base_url: props.settings.base_url,
    system_prompt: props.settings.system_prompt,
});

function resetSystemPrompt(): void {
    form.system_prompt = props.settings.default_system_prompt;
}

const selectedProvider = computed(
    () => props.providers[form.provider] ?? props.providers.custom,
);

const keyOptional = computed(
    () => selectedProvider.value?.requires_key === false,
);

const keyReady = computed(
    () => props.settings.api_key_configured && !form.clear_api_key,
);

const apiKeyPlaceholder = computed(() => {
    if (keyOptional.value) {
        return 'Можно оставить пустым';
    }

    return props.settings.api_key_configured
        ? 'Оставьте пустым, чтобы сохранить текущий'
        : 'Вставьте API-ключ';
});

const keyStatus = computed(() => {
    if (keyReady.value) {
        return 'Ключ настроен';
    }

    return keyOptional.value ? 'Ключ не требуется' : 'Ключ не задан';
});

watch(
    () => form.provider,
    (provider, previous) => {
        const nextPreset = props.providers[provider];
        const previousPreset = props.providers[previous];

        if (nextPreset === undefined) return;

        if (
            form.base_url === '' ||
            form.base_url === previousPreset?.base_url
        ) {
            form.base_url = nextPreset.base_url;
        }

        if (form.model === '' || form.model === previousPreset?.model) {
            form.model = nextPreset.model;
        }
    },
);

function submit(): void {
    form.put('/admin/ai', {
        preserveScroll: true,
        onSuccess: () => {
            form.api_key = '';
            form.clear_api_key = false;
        },
    });
}
</script>

<template>
    <Head title="AI-провайдеры" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
        <header
            class="border-border relative overflow-hidden rounded-3xl border bg-[radial-gradient(circle_at_top_right,var(--color-primary)/0.13,transparent_45%)] p-6 md:p-8"
        >
            <div class="relative max-w-2xl space-y-3">
                <div
                    class="bg-primary/10 text-primary inline-flex size-11 items-center justify-center rounded-2xl"
                >
                    <ServerCog class="size-5" aria-hidden="true" />
                </div>
                <div>
                    <p
                        class="text-muted-foreground font-mono text-xs tracking-[0.16em] uppercase"
                    >
                        Системная админка
                    </p>
                    <h1 class="font-display mt-1 text-3xl tracking-tight">
                        AI-провайдеры
                    </h1>
                </div>
                <p class="text-muted-foreground text-sm leading-6 md:text-base">
                    Выберите, через какой сервис помощник будет вызывать
                    модель. Настройки хранятся на сервере в
                    <code class="text-foreground">.env</code> и не попадают в
                    клиентский JavaScript.
                </p>
            </div>
        </header>

        <form
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]"
            @submit.prevent="submit"
        >
            <Card class="overflow-hidden shadow-none">
                <CardHeader class="border-border border-b">
                    <CardTitle>Подключение</CardTitle>
                    <CardDescription>
                        Изменения начнут работать для новых запросов помощника
                        сразу после сохранения.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-6">
                    <div class="grid gap-2">
                        <Label for="provider">Провайдер</Label>
                        <Select v-model="form.provider" name="provider">
                            <SelectTrigger id="provider" class="w-full">
                                <SelectValue
                                    placeholder="Выберите провайдера"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="(provider, id) in providers"
                                    :key="id"
                                    :value="id"
                                >
                                    {{ provider.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="text-muted-foreground text-xs leading-5">
                            {{ selectedProvider?.description }}
                        </p>
                        <InputError :message="form.errors.provider" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center justify-between gap-3">
                            <Label for="api-key">
                                API-ключ
                                <span
                                    v-if="keyOptional"
                                    class="text-muted-foreground font-normal"
                                >
                                    (необязательно)
                                </span>
                            </Label>
                            <span
                                v-if="settings.api_key_configured"
                                class="text-muted-foreground inline-flex items-center gap-1.5 text-xs"
                            >
                                <CheckCircle2
                                    class="size-3.5 text-emerald-500"
                                />
                                {{ settings.api_key_hint }}
                            </span>
                        </div>
                        <Input
                            id="api-key"
                            v-model="form.api_key"
                            name="api_key"
                            type="password"
                            autocomplete="new-password"
                            :disabled="form.clear_api_key"
                            :placeholder="apiKeyPlaceholder"
                        />
                        <p class="text-muted-foreground text-xs">
                            {{
                                keyOptional
                                    ? 'Локальный Ollama принимает запросы без ключа. Заполните поле, только если сервер требует авторизацию.'
                                    : 'Сохранённый ключ никогда не показывается целиком.'
                            }}
                        </p>
                        <label
                            v-if="settings.api_key_configured"
                            class="text-muted-foreground flex w-fit cursor-pointer items-center gap-2 text-xs"
                        >
                            <input
                                v-model="form.clear_api_key"
                                type="checkbox"
                                class="border-input accent-primary size-4 rounded"
                            />
                            Удалить сохранённый ключ
                        </label>
                        <InputError :message="form.errors.api_key" />
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="model">ID модели</Label>
                            <Input
                                id="model"
                                v-model="form.model"
                                name="model"
                                required
                                placeholder="provider/model-name"
                            />
                            <InputError :message="form.errors.model" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="base-url">Эндпоинт API</Label>
                            <Input
                                id="base-url"
                                v-model="form.base_url"
                                name="base_url"
                                type="url"
                                required
                                placeholder="https://api.example.com/v1/chat/completions"
                            />
                            <p class="text-muted-foreground text-xs leading-5">
                                Полный адрес запроса. К нему ничего не
                                дописывается.
                            </p>
                            <InputError :message="form.errors.base_url" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <div
                            class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <Label for="system-prompt">Системный промпт</Label>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="resetSystemPrompt"
                            >
                                Сбросить к умолчанию
                            </Button>
                        </div>
                        <textarea
                            id="system-prompt"
                            v-model="form.system_prompt"
                            name="system_prompt"
                            rows="16"
                            required
                            class="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[16rem] w-full rounded-md border px-3 py-2 font-mono text-xs leading-5 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <p class="text-muted-foreground text-xs leading-5">
                            Вставьте
                            <code class="text-foreground">{snapshot}</code> —
                            туда подставится JSON бюджета перед каждым
                            запросом. Если текст совпадает с умолчанием, в
                            <code>.env</code> ключ не сохраняется. Поверх
                            любого шаблона сервер всё равно добавляет короткий
                            замок против кода и инъекций; явный оффтоп
                            отсекается до вызова модели.
                        </p>
                        <InputError :message="form.errors.system_prompt" />
                    </div>

                    <Alert>
                        <KeyRound aria-hidden="true" />
                        <AlertTitle>Только AI-переменные</AlertTitle>
                        <AlertDescription>
                            Форма меняет AI_PROVIDER, AI_API_KEY, AI_MODEL,
                            AI_BASE_URL и AI_SYSTEM_PROMPT. Остальные строки
                            <code>.env</code> остаются нетронутыми.
                        </AlertDescription>
                    </Alert>

                    <div
                        class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <Button variant="ghost" as-child>
                            <Link href="/assistant">
                                Открыть помощника
                                <ExternalLink class="size-4" />
                            </Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            <Spinner v-if="form.processing" />
                            Сохранить настройки
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <aside class="space-y-4 lg:sticky lg:top-6">
                <Card class="gap-4 shadow-none">
                    <CardHeader>
                        <CardDescription>Текущий маршрут</CardDescription>
                        <CardTitle class="text-lg">
                            {{ selectedProvider?.label }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4 text-sm">
                        <div>
                            <p class="text-muted-foreground text-xs">Модель</p>
                            <p class="mt-1 font-mono text-xs break-all">
                                {{ form.model || '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-muted-foreground text-xs">
                                Endpoint
                            </p>
                            <p class="mt-1 font-mono text-xs break-all">
                                {{ form.base_url || '—' }}
                            </p>
                        </div>
                        <div class="border-border border-t pt-4">
                            <p
                                class="inline-flex items-center gap-2 text-xs"
                                :class="
                                    keyReady || keyOptional
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                <span
                                    class="size-2 rounded-full bg-current"
                                    aria-hidden="true"
                                />
                                {{ keyStatus }}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </aside>
        </form>
    </div>
</template>
