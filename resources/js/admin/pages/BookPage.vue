<template>
    <a href="#content" class="visually-hidden skip-link">Skip to main content</a>
    <!-- BEGIN GLOBAL THEME SCRIPT -->
    <!-- END GLOBAL THEME SCRIPT -->
    <div class="page">
        <!-- BEGIN NAVBAR  -->
        <!-- END NAVBAR  -->
        <div class="page-wrapper">
            <!-- BEGIN PAGE HEADER -->
            <!-- BEGIN PAGE HEADER -->
            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <!-- Page pre-title -->
                            <h1 class="page-title">Все книги</h1>
                        </div>
                        <!-- Page title actions -->
                        <div class="col-auto ms-auto d-print-none">
                            <div class="btn-list">

                                <a href="#" class="btn btn-primary btn-5 d-none d-sm-inline-block" data-bs-toggle="modal" data-bs-target="#modal-report">
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
                                    Добавить
                                </a>
                                <a
                                    href="#"
                                    class="btn btn-primary btn-6 d-sm-none btn-icon"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modal-report"
                                    aria-label="Create new report"
                                >
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
                                </a>
                            </div>
                            <!-- BEGIN MODAL -->
                            <!-- END MODAL -->
                        </div>
                    </div>
                </div>
            </div>
            <!-- END PAGE HEADER -->
            <!-- END PAGE HEADER -->
            <!-- BEGIN PAGE BODY -->
            <main id="content" class="page-body">
                <div class="container-xl">
                    <div class="row row-cards">
                        <div
                            v-for="book in books"
                            :key="book.id"
                            class="col-md-6 col-lg-3"
                        >
                            <div class="card h-100">
                                <div
                                    class="img-responsive img-responsive-21x9 card-img-top"
                                    :style="{
                    backgroundImage: book.cover_path
                        ? `url(http://localhost:8000/storage/${book.cover_path})`
                        : 'url(/static/photos/no-cover.jpg)'
                }"
                                ></div>

                                <div class="card-body">
                                    <h3 class="card-title">{{ book.title }}</h3>
                                    <p class="text-secondary">
                                        {{ book.description }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <!-- END PAGE BODY -->
            <!-- BEGIN FOOTER -->
            <!--  BEGIN FOOTER  -->
            <footer class="footer footer-transparent d-print-none">
                <div class="container-xl">
                    <div class="row text-center align-items-center flex-row-reverse">
                        <div class="col-lg-auto ms-lg-auto">
                            <nav aria-label="Footer">
                                <ul class="list-inline list-inline-dots mb-0">
                                    <li class="list-inline-item"><a href="https://docs.tabler.io" target="_blank" class="link-secondary" rel="noopener">Documentation</a></li>
                                    <li class="list-inline-item"><a href="./license.html" class="link-secondary">License</a></li>
                                    <li class="list-inline-item">
                                        <a href="https://github.com/tabler/tabler" target="_blank" class="link-secondary" rel="noopener">Source code</a>
                                    </li>
                                    <li class="list-inline-item">
                                        <a href="https://github.com/sponsors/codecalm" target="_blank" class="link-secondary" rel="noopener">
                                            <!-- Download SVG icon from http://tabler.io/icons/icon/heart -->
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
                                                class="icon text-pink icon-inline icon-4"
                                            >
                                                <path d="M19.5 12.572l-7.5 7.428l-7.5 -7.428a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572" />
                                            </svg>
                                            Sponsor
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <div class="col-12 col-lg-auto mt-3 mt-lg-0">
                            <ul class="list-inline list-inline-dots mb-0">
                                <li class="list-inline-item">
                                    Copyright &copy; 2026
                                    <a href="." class="link-secondary">Tabler</a>. All rights reserved.
                                </li>
                                <li class="list-inline-item">
                                    <a href="./changelog.html" class="link-secondary" rel="noopener"> v1.4.0 </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </footer>
            <!--  END FOOTER  -->
            <!-- END FOOTER -->
        </div>
    </div>
    <!-- BEGIN PAGE MODALS -->
    <div class="modal modal-blur fade" id="modal-report" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">

            <form @submit.prevent="createBook">

            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Добавит книгу</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Заголовок</label>
                        <input v-model="form.title" type="text" class="form-control"  placeholder="Your report name" />
                    </div>

                    <div class="row">
                        <div class="col-lg-8">
                            <div class="mb-3">
                                <label class="form-label">Автор</label>
                                <div class="input-group input-group-flat">
                                    <select class="form-select" v-model="form.author_id">
                                        <option value="">Выберите автора</option>
                                        <option
                                            v-for="author in authors"
                                            :key="author.id"
                                            :value="author.id"
                                        >
                                            {{ author.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="mb-3">
                                <label class="form-label">Категория</label>
                                <select class="form-select" v-model="form.category_id">
                                    <option value="">Выберите категорию</option>
                                    <option
                                        v-for="category in categories"
                                        :key="category.id"
                                        :value="category.id"
                                    >
                                        {{ category.name }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label class="form-label">Обложка</label>
                                <input type="file" class="form-control" @change="handleCover" />                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label class="form-label">Дата публикатсии</label>
                                <input v-model="form.published_year" type="number" class="form-control" />
                            </div>
                        </div>
                        <div class="col-lg-12">
                            <div>
                                <label class="form-label">Описание</label>
                                <textarea v-model="form.description" class="form-control" rows="3"></textarea>
                            </div>
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
                        Добавит
                    </button>
                </div>
            </div>

            </form>

        </div>
    </div>

</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../api.js';

const form = reactive({
    author_id: '',
    category_id: '',
    title: '',
    published_year: '',
    description: ''
});

const coverFile = ref([]);

const categories = ref([]);
const authors = ref([]);
const books= ref([]);

function handleCover(event){
    coverFile.value = event.target.files[0];
}


async function fetchBooks(){
    const response = await api.get('/books');

    books.value=response.data;

    console.log(books.value);

}

async function createBook(){

    const formData= new FormData();
    formData.append('author_id', form.author_id);
    formData.append('category_id', form.category_id);
    formData.append('title', form.title);
    formData.append('published_year', form.published_year);
    formData.append('description', form.description);

    if(coverFile.value){
        formData.append('cover',coverFile.value);
    }
    const response = await api.post('/books', formData);
    await fetchBooks();

    console.log('Книга успешно добавлена:', response.data);


}

async function fetchCategories() {
    const response = await api.get('/categories');

    categories.value = response.data;

    console.log(categories.value);
}

async function fetchAuthors(){
    const response = await api.get('/authors');

    authors.value=response.data;

    console.log(authors.value);

}

onMounted(() => {
    fetchCategories();
    fetchAuthors();
    fetchBooks();
});
</script>
