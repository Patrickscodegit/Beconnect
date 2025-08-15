<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Vehicle Data Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <!-- Vehicle Data Import -->
                        <div class="bg-blue-50 p-6 rounded-lg">
                            <h3 class="text-lg font-semibold text-blue-800 mb-3">📊 Vehicle Data</h3>
                            <p class="text-blue-600 mb-4">Import and manage vehicle specification data from CSV files.</p>
                            <div class="space-y-2">
                                <form action="{{ route('upload.csv') }}" method="POST" enctype="multipart/form-data" class="space-y-3">
                                    @csrf
                                    <input type="file" name="csv_file" accept=".csv" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                    <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 transition duration-200">Upload CSV</button>
                                </form>
                            </div>
                        </div>

                        <!-- Vehicle Images -->
                        <div class="bg-green-50 p-6 rounded-lg">
                            <h3 class="text-lg font-semibold text-green-800 mb-3">🖼️ Vehicle Images</h3>
                            <p class="text-green-600 mb-4">Upload and organize vehicle photos by VIN.</p>
                            <div class="space-y-2">
                                <form action="{{ route('upload.vehicle-image') }}" method="POST" enctype="multipart/form-data" class="space-y-3">
                                    @csrf
                                    <input type="text" name="vin" placeholder="Enter VIN (17 characters)" maxlength="17" class="block w-full text-sm border-gray-300 rounded-md">
                                    <input type="file" name="image" accept="image/*" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                                    <button type="submit" class="w-full bg-green-600 text-white py-2 px-4 rounded-md hover:bg-green-700 transition duration-200">Upload Image</button>
                                </form>
                            </div>
                        </div>

                        <!-- Vehicle Documents -->
                        <div class="bg-purple-50 p-6 rounded-lg">
                            <h3 class="text-lg font-semibold text-purple-800 mb-3">📄 Vehicle Documents</h3>
                            <p class="text-purple-600 mb-4">Store manuals, specifications, and other documents.</p>
                            <div class="space-y-2">
                                <form action="{{ route('upload.vehicle-document') }}" method="POST" enctype="multipart/form-data" class="space-y-3">
                                    @csrf
                                    <input type="text" name="vin" placeholder="Enter VIN (17 characters)" maxlength="17" class="block w-full text-sm border-gray-300 rounded-md">
                                    <input type="file" name="document" accept=".pdf,.doc,.docx,.txt" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100">
                                    <button type="submit" class="w-full bg-purple-600 text-white py-2 px-4 rounded-md hover:bg-purple-700 transition duration-200">Upload Document</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Storage Status -->
                    <div class="mt-8 bg-gray-50 p-6 rounded-lg">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3">💾 MinIO Storage Status</h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="text-center">
                                <div class="text-2xl font-bold text-blue-600">✓</div>
                                <div class="text-sm text-gray-600">S3 Compatible</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-green-600">✓</div>
                                <div class="text-sm text-gray-600">Local Development</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-purple-600">✓</div>
                                <div class="text-sm text-gray-600">Production Ready</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-orange-600">✓</div>
                                <div class="text-sm text-gray-600">Secure Access</div>
                            </div>
                        </div>
                        <p class="text-sm text-gray-500 mt-4 text-center">
                            MinIO Server: <span class="font-mono">http://127.0.0.1:9000</span> | 
                            Console: <span class="font-mono">http://127.0.0.1:9001</span>
                        </p>
                    </div>

                    <!-- Recent Files -->
                    <div class="mt-8">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">📁 File Management</h3>
                            <a href="{{ route('files.list') }}" class="bg-gray-600 text-white py-2 px-4 rounded-md hover:bg-gray-700 transition duration-200">View All Files</a>
                        </div>
                        <div class="bg-gray-100 p-4 rounded-lg">
                            <p class="text-gray-600 text-sm">Use the forms above to upload files, or click "View All Files" to manage existing uploads.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
