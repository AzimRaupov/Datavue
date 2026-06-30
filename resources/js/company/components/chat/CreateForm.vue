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
                            <input type="file" class="form-control" @change="handleDataFile" />
                        </div>
                        <div class="col-lg-12">
                            <div>
                                <label class="form-label">Сообшение</label>
                                <textarea class="form-control" rows="3" v-model="form.message"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="#" class="btn btn-link link-secondary btn-3" data-bs-dismiss="modal"> Cancel </a>
                        <button type="submit" class="btn btn-primary btn-5 ms-auto">
                            <!-- Download SVG icon from http://tabler.io/icons/icon/plus -->
                            <svg
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
                                <path d="M12 5l0 14" />
                                <path d="M5 12l14 0" />
                            </svg>
                            Create dashboard
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
import {bootstrap} from "@tabler/core";

const router = useRouter();

const form = reactive({
    message: '',
});

const dataFile = ref(null);

function handleDataFile(event) {
    dataFile.value = event.target.files[0];
}

async function createChat() {
    try {
        const formData = new FormData();
        formData.append('message', form.message);

        if (dataFile.value) {
            formData.append('data_file', dataFile.value);
        }

        const response = await api.post('/chats', formData);

        console.log(response.data);


        const modal = document.getElementById('modal-report');
        const bsModal = new bootstrap.Modal(modal);
        bsModal.hide();

        router.push({ name: 'company.chat', params: { id: response.data.id } });

    } catch (error) {
        console.error(error);
    }
}
</script>

