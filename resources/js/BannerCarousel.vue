<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { ChevronLeft, ChevronRight, Pause, Play } from '@lucide/vue';

const props = defineProps({
  images: { type: Array, required: true },
  label: { type: String, required: true },
  language: { type: String, default: 'en' },
});
defineEmits(['image-error']);

const index = ref(0);
const paused = ref(false);
const hovered = ref(false);
const focused = ref(false);
const activeIndex = computed(() => index.value % (props.images.length || 1));
const activeImage = computed(() => props.images[activeIndex.value]);
const bn = computed(() => props.language === 'bn');
let timer;

function move(offset) {
  const count = props.images.length;
  if (count > 1) index.value = (activeIndex.value + offset + count) % count;
}

onMounted(() => {
  paused.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  timer = window.setInterval(() => {
    if (!paused.value && !hovered.value && !focused.value && !document.hidden) move(1);
  }, 5000);
});
onUnmounted(() => window.clearInterval(timer));
</script>

<template>
  <section class="shop-banner-carousel" :aria-label="label"
    @mouseenter="hovered = true" @mouseleave="hovered = false"
    @focusin="focused = true" @focusout="focused = $event.currentTarget.contains($event.relatedTarget)" @keydown.left.prevent="move(-1)" @keydown.right.prevent="move(1)">
    <div class="banner-image">
      <img v-if="activeImage" :key="activeImage" :src="activeImage" :alt="label"
        @error="$emit('image-error', $event.currentTarget.getAttribute('src'))">
    </div>
    <div v-if="images.length > 1" class="banner-controls">
      <button type="button" :aria-label="bn ? 'আগের ব্যানার' : 'Previous banner'" @click="move(-1)"><ChevronLeft :size="18" /></button>
      <button v-for="(image, slide) in images" :key="image" class="banner-dot" type="button"
        :aria-label="bn ? `ব্যানার ${slide + 1}` : `Banner ${slide + 1}`"
        :aria-current="slide === activeIndex ? 'true' : undefined" @click="index = slide"></button>
      <button type="button" :aria-label="bn ? 'পরের ব্যানার' : 'Next banner'" @click="move(1)"><ChevronRight :size="18" /></button>
      <button type="button" :aria-label="paused ? (bn ? 'চালু করুন' : 'Play carousel') : (bn ? 'বিরতি দিন' : 'Pause carousel')"
        @click="paused = !paused"><Play v-if="paused" :size="15" /><Pause v-else :size="15" /></button>
    </div>
  </section>
</template>

<style scoped>
.shop-banner-carousel {
  position: relative;
  --banner-height: clamp(155px, 43vw, 540px);
  height: auto;
  overflow: hidden;
  border-radius: 8px;
  background: var(--store-hero, #f0eee4);
}

.banner-image { height: var(--banner-height); }

.banner-image img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
  animation: banner-appear .25s ease-out;
}

@keyframes banner-appear { from { opacity: .65; } }
@media (prefers-reduced-motion: reduce) {
  .banner-image img { animation: none; }
}

.banner-controls {
  position: absolute;
  bottom: 8px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  border-radius: 24px;
  background: #173e36ec;
  color: #fff;
}

.banner-controls button {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: inherit;
}

.banner-controls button:hover { background: #ffffff25; }
.banner-controls .banner-dot { width: clamp(23px, 7vw, 32px); }
.banner-dot::before { content: ''; width: 7px; height: 7px; border-radius: 8px; background: #fff9; }
.banner-dot[aria-current]::before { width: 15px; background: #fff; }

@media (max-width: 700px) {
  .shop-banner-carousel { --banner-height: clamp(155px, 48vw, 335px); }

  .banner-controls {
    position: static;
    transform: none;
    justify-content: center;
    border-radius: 0;
    border-top: 1px solid #173e361c;
    background: #fcfbf8;
    color: #173e36;
  }

  .banner-controls button { height: 44px; width: 44px; }
  .banner-controls .banner-dot { width: clamp(20px, 7vw, 32px); }
  .banner-controls button:hover { background: #173e3610; }
  .banner-dot::before { background: currentColor; opacity: .4; }
  .banner-dot[aria-current]::before { opacity: 1; }
}
</style>
