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
                            <h1 class="page-title">Все категории</h1>
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
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Invoices</h3>
                            </div>
                            <div class="card-body border-bottom py-3">
                                <div class="d-flex">
                                    <div class="text-secondary">
                                        Show
                                        <div class="mx-2 d-inline-block">
                                            <input type="text" class="form-control form-control-sm" value="8" size="3" aria-label="Invoices count" />
                                        </div>
                                        entries
                                    </div>
                                    <div class="ms-auto text-secondary">
                                        Search:
                                        <div class="ms-2 d-inline-block">
                                            <input type="text" class="form-control form-control-sm" aria-label="Search invoice" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-selectable card-table table-vcenter text-nowrap datatable">
                                    <thead>
                                    <tr>
                                        <th class="w-1">ID</th>
                                        <th>Имя</th>
                                        <th>Описание</th>
                                        <th>Price</th>

                                    </tr>
                                    </thead>
                                    <tbody>

                                    <template v-for="category in categories" :key="category.id">

                                    <tr>
                                        <td><span class="text-secondary">{{ category.id }}</span></td>
                                        <td><a href="invoice.html" class="text-reset" tabindex="-1">{{ category.title }}</a></td>
                                        <td>
                                            <span class="flag flag-xs flag-country-us me-2"></span>
                                            {{ category.description }}
                                        </td>

                                        <td class="text-end">
                            <span class="dropdown">
                              <button class="btn dropdown-toggle align-text-top" data-bs-boundary="viewport" data-bs-toggle="dropdown">Actions</button>
                              <div class="dropdown-menu dropdown-menu-end">
                                <a class="dropdown-item" href="#"> Action </a>
                                <a class="dropdown-item" href="#"> Another action </a>
                              </div>
                            </span>
                                        </td>
                                    </tr>

                                    </template>

                                    </tbody>
                                </table>
                            </div>
                            <div class="card-footer">
                                <div class="row g-2 justify-content-center justify-content-sm-between">
                                    <div class="col-auto d-flex align-items-center">
                                        <p class="m-0 text-secondary">Showing <strong>1 to 8</strong> of <strong>16 entries</strong></p>
                                    </div>
                                    <div class="col-auto">
                                        <!-- BEGIN PAGINATION -->
                                        <ul class="pagination m-0 ms-auto">
                                            <li class="page-item disabled">
                                                <a class="page-link" href="#" tabindex="-1" aria-disabled="true">
                                                    <!-- Download SVG icon from http://tabler.io/icons/icon/chevron-left -->
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
                                                        class="icon icon-1"
                                                    >
                                                        <path d="M15 6l-6 6l6 6" />
                                                    </svg>
                                                </a>
                                            </li>
                                            <li class="page-item">
                                                <a class="page-link" href="#">1</a>
                                            </li>
                                            <li class="page-item">
                                                <a class="page-link" href="#">2</a>
                                            </li>
                                            <li class="page-item active">
                                                <a class="page-link" href="#">3</a>
                                            </li>
                                            <li class="page-item">
                                                <a class="page-link" href="#">4</a>
                                            </li>
                                            <li class="page-item">
                                                <a class="page-link" href="#">5</a>
                                            </li>
                                            <li class="page-item">
                                                <a class="page-link" href="#">
                                                    <!-- Download SVG icon from http://tabler.io/icons/icon/chevron-right -->
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
                                                        class="icon icon-1"
                                                    >
                                                        <path d="M9 6l6 6l-6 6" />
                                                    </svg>
                                                </a>
                                            </li>
                                        </ul>
                                        <!-- END PAGINATION -->
                                    </div>
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
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Добавит категорию</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <form @submit.prevent="createCategory">


                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Имя</label>
                        <input type="text" class="form-control" v-model="form.name" placeholder="Your report name" />
                    </div>
                    <div class="col-lg-12">
                        <div>
                            <label class="form-label">Описание</label>
                            <textarea class="form-control" v-model="form.description" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="#" class="btn btn-link link-secondary btn-3" data-bs-dismiss="modal"> Отмена </a>
                    <button type="submit" href="#" class="btn btn-primary btn-5 ms-auto" >
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
                </form>

            </div>

        </div>

    </div>


</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api from '../api.js';

const form = reactive({
    name: '',
    description: ''
});


const categories=ref([]);

async function fetchCategories() {
    const response = await api.get('/categories');

    categories.value = response.data;

    console.log(categories.value);
}

async function createCategory(){

    const response =await api.post('/categories',form);

    console.log(response);

}


onMounted(() => {
    fetchCategories();
});

</script>
