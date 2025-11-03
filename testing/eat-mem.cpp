#include <iostream>
#include <vector>
#include <cstdlib>
#include <cstring>

int main(int argc, char *argv[]) {
    if (argc != 2) {
        std::cerr << "Usage: " << argv[0] << " <megabytes>" << std::endl;
        return 1;
    }

    int megabytes = std::atoi(argv[1]);
    long long bytes = (long long)megabytes * 1024 * 1024;

    std::cout << "Allocating " << megabytes << " megabytes (" << bytes << " bytes)..." << std::endl;

    try {
        std::vector<char> mem(bytes, 0);
        std::cout << "Allocation successful." << std::endl;
    } catch (const std::bad_alloc& e) {
        std::cerr << "Allocation failed: " << e.what() << std::endl;
        return 1;
    }

    return 0;
}
