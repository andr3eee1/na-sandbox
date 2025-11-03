#include <iostream>
#include <cstdlib>
#include <ctime>

int main(int argc, char *argv[]) {
    if (argc != 2) {
        std::cerr << "Usage: " << argv[0] << " <seconds>" << std::endl;
        return 1;
    }

    double seconds = std::atof(argv[1]);
    clock_t start_time = std::clock();
    double clocks_to_run = seconds * CLOCKS_PER_SEC;

    std::cout << "Eating CPU time for " << seconds << " seconds..." << std::endl;

    while (std::clock() - start_time < clocks_to_run) {
        // Busy-wait doing some math
        double result = 0;
        for (int i = 0; i < 1000; i++) {
            result += i * 3.14;
        }
    }

    std::cout << "Done." << std::endl;

    return 0;
}
