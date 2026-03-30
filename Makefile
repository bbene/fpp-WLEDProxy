# ── FPP WLED API Proxy Plugin — Makefile ─────────────────────────────────────
#
# Builds WLEDProxyPlugin.so for the fppd daemon.
#
# Prerequisites:
#   - FPP source tree at $(FPPDIR)/src  (default /opt/fpp/src)
#   - jsoncpp   (libjsoncpp-dev)
#   - g++ / C++17
#
# Usage:
#   make                  # build the .so
#   make clean            # remove build artifacts
#   make install          # copy .so to FPP plugin directory
# ─────────────────────────────────────────────────────────────────────────────

# ── Paths ──────────────────────────────────────────────────────────────────────
FPPDIR        ?= /opt/fpp
PLUGIN_NAME   := fpp-WLEDProxy
PLUGIN_DIR    := /home/fpp/media/plugins/$(PLUGIN_NAME)

# Source and output
SRC_DIR       := src
OBJ_DIR       := .build
TARGET        := $(SRC_DIR)/WLEDProxyPlugin.so

SRCS          := $(SRC_DIR)/WLEDProxyPlugin.cpp
OBJS          := $(patsubst $(SRC_DIR)/%.cpp,$(OBJ_DIR)/%.o,$(SRCS))

# ── Compiler flags ─────────────────────────────────────────────────────────────
CXX           := g++
CXXFLAGS      := -std=c++17 -Wall -Wextra -O2 -fPIC \
                 -I$(FPPDIR)/src \
                 -I$(FPPDIR)/src/common \
                 -I/usr/include/jsoncpp

LDFLAGS       := -shared -Wl,-soname,WLEDProxyPlugin.so
LIBS          := -ljsoncpp -lpthread

# ── Targets ────────────────────────────────────────────────────────────────────

.PHONY: all clean install

all: $(TARGET)

$(TARGET): $(OBJS)
	$(CXX) $(LDFLAGS) -o $@ $^ $(LIBS)
	@echo "Built: $(TARGET)"

$(OBJ_DIR)/%.o: $(SRC_DIR)/%.cpp | $(OBJ_DIR)
	$(CXX) $(CXXFLAGS) -c -o $@ $<

$(OBJ_DIR):
	mkdir -p $(OBJ_DIR)

clean:
	rm -rf $(OBJ_DIR) $(TARGET)

install: all
	@echo "Installing plugin .so to $(PLUGIN_DIR)/src/"
	mkdir -p $(PLUGIN_DIR)/src
	cp $(TARGET) $(PLUGIN_DIR)/src/
	@echo "Done. Restart fppd to load the plugin."
