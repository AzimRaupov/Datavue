<template>
    <div class="modal modal-blur fade" id="modal-report" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <form @submit.prevent="createChat" enctype="multipart/form-data">

                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Data to Dashboard</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Файл Exel,Csv,Sql</label>
                            <input type="file" class="form-control" @change="handleDataFile" :disabled="isLoading" />
                        </div>
                        <div class="col-lg-12">
                            <div>
                                <label class="form-label">Сообщение</label>
                                <textarea class="form-control" rows="3" v-model="form.message" :disabled="isLoading"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="#" class="btn btn-link link-secondary btn-3" data-bs-dismiss="modal" v-if="!isLoading"> Cancel </a>

                        <button type="submit" class="btn btn-primary btn-5 ms-auto" :disabled="isLoading">
                            <span v-if="isLoading" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                            <svg
                                v-else
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                                focusable="false"
                                class="icon icon-2"
                            >
                                <path d="M16 18a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2zm0 -12a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2zm-11 5a3 3 0 0 1 3 3a3 3 0 0 1 3 -3a3 3 0 0 1 -3 -3a3 3 0 0 1 -3 3z" />
                            </svg>
                            {{ isLoading ? 'Loading...' : 'Create dashboard' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { useRouter } from 'vue-router';

import api from '../../api.js';
import { bootstrap } from "@tabler/core/";

const router = useRouter();

const form = reactive({
    message: '',
});

const dataFile = ref(null);
// Состояние загрузки
const isLoading = ref(false);

function handleDataFile(event) {
    dataFile.value = event.target.files[0];
}

// Закрываем модалку и ждём, пока анимация реально завершится
// (событие hidden.bs.modal), чтобы Bootstrap успел убрать backdrop
// и класс modal-open с body до того, как роутер снесёт DOM
function closeModalAndWait(modalEl) {
    return new Promise((resolve) => {
        const bsModal = bootstrap.Modal.getInstance(modalEl);

        if (!bsModal) {
            resolve();
            return;
        }

        const onHidden = () => {
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            resolve();
        };

        modalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });
        bsModal.hide();

        // Подстраховка: если событие по какой-то причине не сработало
        // (например модалку успели уничтожить раньше) — чистим руками
        setTimeout(() => {
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            cleanupBackdrops();
            resolve();
        }, 500);
    });
}

// Ручная зачистка backdrop-ов и классов на body — на всякий случай
function cleanupBackdrops() {
    document.querySelectorAll('.modal-backdrop').forEach((el) => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
}

async function createChat() {
    // Предотвращаем повторный вызов, если запрос уже идет
    if (isLoading.value) return;

    try {
        isLoading.value = true; // Включаем лоадер

        const formData = new FormData();
        formData.append('message', form.message);

        if (dataFile.value) {
            formData.append('data_file', dataFile.value);
        }

        const response = await api.post('/chats', formData);

        console.log(response.data);

        const modal = document.getElementById('modal-report');

        // Ждём, пока модалка полностью закроется (и backdrop уберётся)
        await closeModalAndWait(modal);

        await router.push({
            name: 'company.chat',
            params: { id: response.data.chat.id }
        });

    } catch (error) {
        console.error(error);
        // Выключаем лоадер только при ошибке, чтобы пользователь мог попробовать снова
        isLoading.value = false;
    }
}
</script>
