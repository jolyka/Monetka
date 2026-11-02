<script setup lang="ts">
import { Pause, Play } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{
    src: string;
}>();

const audio = ref<HTMLAudioElement | null>(null);
const playing = ref(false);
const current = ref(0);
const duration = ref(0);

const total = computed(() =>
    Number.isFinite(duration.value) && duration.value > 0 ? duration.value : 0,
);
const progress = computed(() => {
    const basis = total.value > 0 ? total.value : 30;

    return Math.min(100, (current.value / basis) * 100);
});
const label = computed(() =>
    format(playing.value || current.value > 0 ? current.value : total.value),
);

function format(seconds: number): string {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '0:00';
    }

    const whole = Math.floor(seconds);

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

function syncDuration(): void {
    const value = audio.value?.duration ?? 0;
    duration.value = Number.isFinite(value) ? value : 0;
}

async function toggle(): Promise<void> {
    const element = audio.value;

    if (!element) {
        return;
    }

    if (element.paused) {
        await element.play().catch(() => {
            playing.value = false;
        });

        return;
    }

    element.pause();
}

function seek(event: MouseEvent): void {
    const element = audio.value;
    const bar = event.currentTarget;

    if (!element || !(bar instanceof HTMLElement)) {
        return;
    }

    const bounds = bar.getBoundingClientRect();
    const basis = total.value > 0 ? total.value : 30;
    const ratio = Math.min(1, Math.max(0, (event.clientX - bounds.left) / bounds.width));

    element.currentTime = ratio * basis;
    current.value = element.currentTime;
}

watch(
    () => props.src,
    () => {
        playing.value = false;
        current.value = 0;
        duration.value = 0;
    },
);

onBeforeUnmount(() => audio.value?.pause());
</script>

<template>
    <div class="flex w-56 max-w-full items-center gap-2.5 whitespace-normal">
        <button
            type="button"
            class="grid size-8 shrink-0 place-items-center rounded-full bg-white/20 transition hover:bg-white/30"
            :aria-label="playing ? 'Пауза' : 'Слушать'"
            @click="toggle"
        >
            <Pause v-if="playing" class="size-3.5" />
            <Play v-else class="size-3.5 translate-x-px" />
        </button>
        <button
            type="button"
            class="relative h-1 min-w-0 flex-1 cursor-pointer rounded-full bg-white/30"
            aria-label="Позиция записи"
            @click="seek"
        >
            <span
                class="absolute inset-y-0 left-0 rounded-full bg-white"
                :style="{ width: `${progress}%` }"
            />
        </button>
        <span class="w-8 shrink-0 text-right font-mono text-[11px] tabular-nums opacity-80">
            {{ label }}
        </span>
        <audio
            ref="audio"
            :src="src"
            preload="metadata"
            class="hidden"
            @play="playing = true"
            @pause="playing = false"
            @ended="
                playing = false;
                current = 0;
            "
            @timeupdate="current = audio?.currentTime ?? 0"
            @loadedmetadata="syncDuration"
            @durationchange="syncDuration"
        />
    </div>
</template>
